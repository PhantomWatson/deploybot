<?php
declare(strict_types=1);

namespace App;

use Exception;

/**
 * Handles a webhook request from GitHub by queuing a background deployment, so that the request can finish quickly
 */
class Webhook
{
    private Slack $slack;
    private bool $validateIpAddresses = false;

    /**
     * Webhook constructor
     */
    public function __construct()
    {
        $this->slack = new Slack();
    }

    /**
     * Responds to the current request
     *
     * @return void
     */
    public function handle(): void
    {
        $this->validateIpAddress();

        if (Request::isGithubPing()) {
            $this->handleGithubPing();

            return;
        }

        $repoName = $this->getRepoName();
        $this->slack->repoName = $repoName;
        $branch = Request::getBranch();
        $site = Site::getSite($repoName);
        if (!Site::isValidBranch($branch, $site)) {
            echo "$branch branch can't be auto-deployed. ";
            echo 'Branches that can be auto-deployed: ' . implode(', ', Site::getAvailableBranches($site));

            return;
        }

        $jobPath = $this->writeJob($repoName, $branch, Request::getDeployTrigger());
        if ($jobPath === null || !$this->startWorker($jobPath)) {
            $msg = "Unable to start a deployment of the $branch branch of $repoName";
            http_response_code(500);
            echo $msg;
            $this->slack->addLine("*$msg*");
            $this->slack->send();

            return;
        }

        http_response_code(202);
        echo "Deployment of the $branch branch of $repoName queued. Results will be posted to Slack.";
    }

    /**
     * Forbids unknown IP addresses
     *
     * @return void
     */
    private function validateIpAddress(): void
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
     * Replies to a GitHub ping
     *
     * @return void
     */
    private function handleGithubPing(): void
    {
        $repoName = $this->getRepoName();
        $this->slack->repoName = $repoName;
        $msg = 'GitHub ping received for ' . $repoName;
        echo $msg;
        $this->slack->addLine($msg);
        $this->slack->send();
    }

    /**
     * Retrieves this request's valid repository name or aborts with an error if it's missing or invalid
     *
     * @return string
     */
    private function getRepoName(): string
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
     * Saves the details of a deployment where the background worker can read them
     *
     * @param string $repoName Name of GitHub repository
     * @param string $branch Name of branch to deploy
     * @param string $trigger Message describing what caused this deployment
     * @return string|null Path of the job file, or NULL if it couldn't be written
     */
    private function writeJob(string $repoName, string $branch, string $trigger): ?string
    {
        $dir = dirname(__DIR__) . '/jobs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $path = $dir . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode(
            [
                'branch' => $branch,
                'repo' => $repoName,
                'trigger' => $trigger,
            ],
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json !== false && file_put_contents($path, $json) !== false ? $path : null;
    }

    /**
     * Starts the background worker for a job, without waiting for it to finish
     *
     * @param string $jobPath Path of the job file
     * @return bool True if the worker was started
     */
    private function startWorker(string $jobPath): bool
    {
        $php = Config::get('php');
        $script = dirname(__DIR__) . '/scripts/deploy-worker.php';
        if (!is_executable($php) || !is_file($script)) {
            return false;
        }

        $command = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($jobPath)
        );
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            return false;
        }

        // The shell only starts the worker in the background, so this returns right away
        proc_close($process);

        return true;
    }
}
