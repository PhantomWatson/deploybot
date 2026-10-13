<?php
namespace App;

use Exception;

/**
 * Class Deploy
 *
 * @package App
 * @property array $site
 * @property Log $log
 * @property ScreenOutput $screenOutput
 * @property Slack $slack
 * @property string $branch
 * @property string $repoName
 */
class Deploy
{
    public $branch;
    public $lastExitCode;
    public $lockHandle;
    public $log;
    public $repoName;
    public $screenOutput;
    public $site;
    public $slack;
    private $validateIpAddresses = false;

    /**
     * Deploy constructor
     */
    public function __construct()
    {
        $this->validateIpAddress();
        $this->initializeLogging();
        $this->setEnvVars();

        if (Request::isGithubPing()) {
            $this->handleGithubPing();
        } else {
            $this->logTrigger();
            $this->repoName = $this->getRepoName();
            $this->slack->repoName = $this->repoName;
            $this->branch = Request::getBranch();

            $this->site = Site::getSite($this->repoName);
            $this->validateBranch();
            $this->runWithLock();
        }

        $this->addSiteLinkToSlack();
        $this->sendSlackOutput();
    }

    /**
     * Forbids unknown IP addresses
     *
     * @return void
     */
    private function validateIpAddress()
    {
        if (!$this->validateIpAddresses) {
            return;
        }

        if (Request::isAuthorized()) {
            return;
        }

        try {
            if (!Request::isGitHub()) {
                header('HTTP/1.1 403 Forbidden');
                echo 'Sorry, your IP address (' . Request::getIpAddress() . ') isn\'t authorized. 🙁';
                exit;
            }
        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            echo $e->getMessage();
            exit;
        }
    }

    /**
     * Replies and aborts execution if this request is a Github ping
     *
     * @return void
     */
    private function handleGithubPing()
    {
        if (!Request::isGithubPing()) {
            return;
        }
        $msg = 'GitHub ping received for ' . $this->getRepoName();
        echo $msg;
        $this->log->addLine($msg);
        $this->slack->addLine($msg);
    }

    /**
     * Retrieves this request's valid repository name or throws an error if it's missing or invalid
     *
     * @return string
     */
    private function getRepoName()
    {
        $repoName = Request::getRepoName();
        if (!$repoName) {
            header('HTTP/1.1 404 Not Found');
            echo 'No valid repo name provided';
            exit;
        }
        if (!Site::isValid($repoName)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Unrecognized repo name: ' . $repoName;
            exit;
        }

        return $repoName;
    }

    /**
     * Halts execution if the current branch can't be auto-deployed
     *
     * @return void
     */
    private function validateBranch()
    {
        if (!Site::isValidBranch($this->branch, $this->site)) {
            echo "$this->branch branch can't be auto-deployed. ";
            echo "Branches that can be auto-deployed: " . implode(', ', Site::getAvailableBranches($this->site));
            exit;
        }
    }

    /**
     * Changes the current working directory to the appropriate website
     *
     * @return void
     */
    private function openSiteDir()
    {
        $sitesRoot = '/home/phanto41';
        $siteDir = $sitesRoot . '/' . $this->site[$this->branch]['dir'];
        if (!file_exists($siteDir)) {
            echo "$siteDir not found";
            exit;
        }

        chdir($siteDir);
    }

    /**
     * Runs the deployment unless one is already running for the same site directory
     *
     * If one is running, this request leaves a "pending" marker and returns. The running deployment checks for that
     * marker when it finishes and deploys again, which picks up every push that arrived in the meantime in one run.
     *
     * @return void
     */
    private function runWithLock()
    {
        $lockDir = dirname(__DIR__) . '/locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            echo "Unable to create $lockDir";
            exit;
        }

        $lockName = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->site[$this->branch]['dir']);
        $pendingPath = "$lockDir/$lockName.pending";
        $this->lockHandle = fopen("$lockDir/$lockName.lock", 'c');
        if (!$this->lockHandle) {
            echo "Unable to open lock file for $lockName";
            exit;
        }

        if (!$this->acquireLock($pendingPath)) {
            $msg = "A deployment of the $this->branch branch of $this->repoName is already running. "
                . 'Another will start when it finishes.';
            $this->log->addLine($msg);
            $this->slack->addLine($msg);
            $this->screenOutput->add($msg . "\n");

            return;
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
     * @return void
     */
    private function runCommands()
    {
        $phpVersion = $this->getPhpVersion();
        $this->openSiteDir();
        $commands = $this->site[$this->branch]['commands'] // branch-specific commands
            ?? $this->site['commands']                     // branch-agnostic commands
            ?? [];                                         // no commands
        foreach ($commands as $command) {
            $command = $this->adaptCommandToPhpVersion($command, $phpVersion);
            $results = $this->runCommand($command);
            if (!$results) {
                $results = '(no output)';
            }
            if ($this->lastExitCode !== 0) {
                $results = trim($results) . "\n(exit code $this->lastExitCode)";
            }

            $this->screenOutput->add('$ ', '#6BE234');
            $this->screenOutput->add($command . "\n", '#729FCF');
            $this->screenOutput->add(htmlentities(trim($results)) . "\n\n");

            $this->slack->addAbridged($command, $results);
            $this->sendSlackOutput();
        }
    }

    /**
     * Initializes output for the log, slack, and screen, and logs the trigger message
     *
     * @return void
     */
    private function initializeLogging()
    {
        $this->log = new Log();
        $this->screenOutput = new ScreenOutput();
        $this->slack = new Slack();
    }

    private function logTrigger()
    {
        $triggerMsg = Request::getDeployTrigger();
        $this->log->addLine($triggerMsg);
        $this->log->addLine('');
        $this->slack->addTriggerMsg($triggerMsg);
    }

    /**
     * Sends a message to Slack or outputs error information
     *
     * @return void
     */
    private function sendSlackOutput()
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
    private function addSiteLinkToSlack()
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

        return $output;
    }

    /**
     * Sets environment variables
     *
     * @return void
     */
    private function setEnvVars()
    {
        //putenv('COMPOSER_HOME=/home/okbvtfr/.composer');
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
