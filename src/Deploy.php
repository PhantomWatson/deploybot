<?php
declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Class Deploy
 *
 * Deploys one branch of one site. Meant to be run by scripts/deploy-worker.php
 *
 * @package App
 * @property array $site
 * @property BuildState $buildState
 * @property Log $log
 * @property ScreenOutput $screenOutput
 * @property Slack $slack
 * @property string $branch
 * @property string $repoName
 * @property string $trigger
 * @property int $lastExitCode
 * @property resource|null $lockHandle
 */
class Deploy
{
    public string $branch;
    public BuildState $buildState;
    public int $lastExitCode;
    public $lockHandle = null;
    public Log $log;
    public string $repoName;
    public ScreenOutput $screenOutput;
    public array $site;
    public Slack $slack;
    public string $trigger;

    /**
     * Deploy constructor
     *
     * @param string $repoName Name of GitHub repository
     * @param string $branch Name of branch to deploy
     * @param string $trigger Message describing what caused this deployment
     * @param Slack|null $slack Optional replacement for the default Slack client
     */
    public function __construct(string $repoName, string $branch, string $trigger, ?Slack $slack = null)
    {
        $this->repoName = $repoName;
        $this->branch = $branch;
        $this->trigger = $trigger;
        $this->log = new Log();
        $this->screenOutput = new ScreenOutput();
        $this->slack = $slack ?? new Slack();
        $this->slack->repoName = $repoName;
    }

    /**
     * Deploys the site and reports the results to Slack and the log
     *
     * @return void
     */
    public function run(): void
    {
        try {
            $this->log->addLine($this->trigger);
            $this->log->addLine('');
            $this->slack->addTriggerMsg($this->trigger);

            $this->validate();
            if ($this->runWithLock()) {
                $this->addSiteLinkToSlack();
            }
        } catch (Throwable $e) {
            $this->reportError('Deployment failed: ' . $e->getMessage());
        }

        $this->sendSlackOutput();
        $this->log->addHtml($this->screenOutput->content);
        $this->log->write($this->repoName);
    }

    /**
     * Reports a PHP fatal error, which can't be caught as an exception. Intended to be a shutdown function.
     *
     * @return void
     */
    public function reportFatalError(): void
    {
        $error = error_get_last();
        $fatalTypes = [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE, E_RECOVERABLE_ERROR];
        if (!$error || !in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $this->reportError("Deployment crashed: {$error['message']} in {$error['file']}:{$error['line']}");
        $this->sendSlackOutput();
    }

    /**
     * Adds an error message to the output for the screen, log, and Slack
     *
     * @param string $msg Message
     * @return void
     */
    private function reportError(string $msg): void
    {
        $this->log->addLine($msg);
        $this->slack->addLine("*$msg*");
        $this->screenOutput->add($msg . "\n", 'red');
    }

    /**
     * Throws an exception if this repository and branch can't be auto-deployed
     *
     * @return void
     * @throws RuntimeException
     */
    private function validate(): void
    {
        if (!Site::isValid($this->repoName)) {
            throw new RuntimeException("Unrecognized repo name: $this->repoName");
        }

        $this->site = Site::getSite($this->repoName);
        if (!Site::isValidBranch($this->branch, $this->site)) {
            $available = implode(', ', Site::getAvailableBranches($this->site));
            throw new RuntimeException("$this->branch branch can't be auto-deployed. Available: $available");
        }
    }

    /**
     * Changes the current working directory to the appropriate website
     *
     * @return void
     * @throws RuntimeException
     */
    private function openSiteDir(): void
    {
        $siteDir = Config::get('sitesRoot') . '/' . $this->site[$this->branch]['dir'];
        if (!file_exists($siteDir)) {
            throw new RuntimeException("$siteDir not found");
        }

        chdir($siteDir);
    }

    /**
     * Runs the deployment unless one is already running for the same site directory
     *
     * If one is running, this request leaves a "pending" marker and returns. The running deployment checks for that
     * marker when it finishes and deploys again, which picks up every push that arrived in the meantime in one run.
     *
     * @return bool True if this request deployed the site, or false if it left that to the deployment in progress
     * @throws RuntimeException
     */
    private function runWithLock(): bool
    {
        $lockDir = dirname(__DIR__) . '/locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new RuntimeException("Unable to create $lockDir");
        }

        $lockName = $this->getSiteKey();
        $pendingPath = "$lockDir/$lockName.pending";
        $lockHandle = fopen("$lockDir/$lockName.lock", 'c');
        if ($lockHandle === false) {
            throw new RuntimeException("Unable to open lock file for $lockName");
        }
        $this->lockHandle = $lockHandle;

        if (!$this->acquireLock($pendingPath)) {
            $msg = "A deployment of the $this->branch branch of $this->repoName is already running. "
                . 'Another will start when it finishes.';
            $this->log->addLine($msg);
            $this->slack->addLine($msg);
            $this->screenOutput->add($msg . "\n");

            return false;
        }

        $isFollowUp = false;
        do {
            // Clear the marker before deploying so that a push that arrives during this deployment is noticed
            if (file_exists($pendingPath)) {
                unlink($pendingPath);
            }

            if ($isFollowUp) {
                $msg = 'Deploying again to include changes pushed during the previous deployment';
                $this->slack->addLine($msg);
                $this->screenOutput->add($msg . "\n\n");
            }

            $this->runCommands();

            // Unlock before checking for the marker. A request that fails to lock and then leaves a marker retries the
            // lock afterward, so either it gets the lock itself or this check finds its marker. Neither can be missed.
            flock($this->lockHandle, LOCK_UN);
            $isFollowUp = true;
        } while (file_exists($pendingPath) && flock($this->lockHandle, LOCK_EX | LOCK_NB));

        return true;
    }

    /**
     * Returns a name, safe for use in file names, that identifies the current site directory
     *
     * @return string
     */
    private function getSiteKey(): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $this->site[$this->branch]['dir']);
    }

    /**
     * Attempts to lock this site's deployments, leaving a "pending" marker if another deployment already holds the lock
     *
     * @param string $pendingPath Path of the marker file
     * @return bool True if the lock was acquired
     */
    private function acquireLock(string $pendingPath): bool
    {
        if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            return true;
        }

        touch($pendingPath);

        // The other deployment may have finished after the attempt above but before the marker existed, in which case
        // nothing would ever look at the marker. Try again so that this request runs the deployment in that case.
        return flock($this->lockHandle, LOCK_EX | LOCK_NB);
    }

    /**
     * Runs all of the commands for deploying an update and outputs the results to the screen and Slack
     *
     * A command can be a string, or an array with these keys:
     * - command: The command to run
     * - key: Optional name that identifies this step (default: the command)
     * - outputs: Optional paths that the command produces. If any is missing, the command isn't skipped.
     * - paths: Paths in the repository that the command's results depend on. The command is skipped if nothing in
     *   them has changed since the last time the command succeeded.
     *
     * @return void
     */
    private function runCommands(): void
    {
        $phpVersion = $this->getPhpVersion();
        $this->openSiteDir();
        $this->buildState = new BuildState(dirname(__DIR__) . '/state/' . $this->getSiteKey() . '.json');
        $steps = $this->site[$this->branch]['commands'] // branch-specific commands
            ?? $this->site['commands']                  // branch-agnostic commands
            ?? [];                                      // no commands
        foreach ($steps as $step) {
            if (is_string($step)) {
                $step = ['command' => $step];
            }
            $key = $step['key'] ?? $step['command'];

            // Commit that this command would build, for commands that can be skipped
            $head = isset($step['paths']) ? Git::head() : null;
            $skipReason = $this->getSkipReason($step, $key, $head);
            if ($skipReason !== null) {
                $this->screenOutput->add('$ ', '#6BE234');
                $this->screenOutput->add(htmlspecialchars($step['command']) . "\n", '#729FCF');
                $this->screenOutput->add("(skipped: $skipReason)\n\n");
                $this->slack->addLine("*$key:* skipped ($skipReason)");
                continue;
            }

            $command = $this->adaptCommandToPhpVersion($step['command'], $phpVersion);
            $results = $this->runCommand($command);
            if (!$results) {
                $results = '(no output)';
            }
            if ($this->lastExitCode !== 0) {
                $results = trim($results) . "\n(exit code $this->lastExitCode)";
            }

            $this->screenOutput->add('$ ', '#6BE234');
            $this->screenOutput->add(htmlspecialchars($command) . "\n", '#729FCF');
            $this->screenOutput->add(htmlentities(trim($results)) . "\n\n");

            $this->slack->addAbridged($command, $results);
            $this->sendSlackOutput();

            if ($head !== null && $this->lastExitCode === 0) {
                $this->buildState->set($key, $head);
            }
        }
    }

    /**
     * Returns the reason that a step can be skipped, or NULL if it needs to run
     *
     * Anything unexpected (no recorded success, a recorded commit that no longer exists, a git error) means that the
     * step runs.
     *
     * @param array $step Step from the site's commands
     * @param string $key Identifies the step in the build state
     * @param string|null $head Current commit, if known
     * @return string|null
     */
    private function getSkipReason(array $step, string $key, ?string $head): ?string
    {
        $paths = $step['paths'] ?? [];
        if (!$paths || $head === null) {
            return null;
        }

        $lastBuilt = $this->buildState->get($key);
        if ($lastBuilt === null || !Git::hasCommit($lastBuilt)) {
            return null;
        }

        foreach ($step['outputs'] ?? [] as $output) {
            if (!file_exists($output)) {
                return null;
            }
        }

        if (!Git::unchangedSince($lastBuilt, $paths)) {
            return null;
        }

        return 'no changes in ' . implode(', ', $paths);
    }

    /**
     * Sends a message to Slack or outputs error information
     *
     * @return void
     */
    private function sendSlackOutput(): void
    {
        if (!$this->slack->content) {
            $this->screenOutput->add('No message to send to Slack' . PHP_EOL);
        } elseif ($this->slack->send()) {
            $this->screenOutput->add('Sent message to Slack' . PHP_EOL);
        } else {
            $this->screenOutput->add('Error sending message to Slack: ');
            $this->screenOutput->add($this->slack->curlResult  . PHP_EOL, 'red');
        }
    }

    /**
     * Adds a link to view the updated site to slack
     *
     * @return void
     */
    private function addSiteLinkToSlack(): void
    {
        if (isset($this->site[$this->branch]['url'])) {
            $this->slack->addLine('*Load updated site:* ' . $this->site[$this->branch]['url']);
        }
    }

    private function runCommand(string $command): string
    {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['redirect', 1], // stderr, sharing stdout's pipe so that neither can fill up and block the process
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            $this->lastExitCode = -1;

            return 'Failed to start process';
        }

        $output = stream_get_contents($pipes[1]);

        fclose($pipes[1]);
        $this->lastExitCode = proc_close($process);

        return $output === false ? '' : $output;
    }

    /**
     * Returns the major PHP version associated with the current site/branch
     *
     * @return int
     */
    private function getPhpVersion(): int
    {
        if ($this->site[$this->branch]['php']) {
            return (int)$this->site[$this->branch]['php'];
        }

        return 8;
    }

    /**
     * Temporarily changes the path to PHP for this command, if required
     *
     * @param string $command
     * @param int $phpVersion PHP major version
     * @return string
     */
    private function adaptCommandToPhpVersion(string $command, int $phpVersion): string
    {
        if ($phpVersion == 7) {
            return 'env PATH="/opt/cpanel/ea-php74/root/usr/bin:$PATH" ' . $command;
        }

        return $command;
    }
}
