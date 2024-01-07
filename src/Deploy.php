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
            $this->branch = Request::getBranch();

            $this->site = Site::getSite($this->repoName);
            $this->validateBranch();
            $this->runCommands();
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
        $appDir = dirname(dirname(__FILE__));
        $sitesRoot = dirname($appDir);
        $siteDir = $sitesRoot . '/' . $this->site[$this->branch]['dir'];
        if (!file_exists($siteDir)) {
            echo "$siteDir not found";
            exit;
        }

        chdir($siteDir);
    }

    /**
     * Runs all of the commands for deploying an update and outputs the results to the screen and Slack
     *
     * @return void
     */
    private function runCommands()
    {
        $phpVersion = $this->getPhpVersion();
        $appDir = dirname(dirname(__FILE__));
        $this->openSiteDir();
        $commands = include $appDir . '/config/commands.php';
        if (isset($this->site['commands'])) {
            $commands = array_merge($commands, $this->site['commands']);
        }
        foreach ($commands as $command) {
            $command = $this->adaptCommandToPhpVersion($command, $phpVersion);
            $results = shell_exec("$command 2>&1");
            if (!$results) {
                $results = '(no output)';
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
        if ($this->slack->send()) {
            $this->screenOutput->add('Sent message to Slack');
        } else {
            $this->screenOutput->add('Error sending message to Slack: ');
            $this->screenOutput->add($this->slack->curlResult, 'red');
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
