<?php
declare(strict_types=1);

namespace App;

use Exception;
use stdClass;

class Request
{
    /**
     * Returns the IP address making the current request
     *
     * @return string
     */
    public static function getIpAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Returns the name of the GitHub repository associated with the current request
     *
     * @return string Empty if there is no repository name
     */
    public static function getRepoName(): string
    {
        if (isset($_POST['payload'])) {
            $payload = json_decode($_POST['payload']);
            if (isset($payload->repository->name)) {
                return (string)$payload->repository->name;
            }

            return '';
        }

        if (isset($_GET['path'])) {
            return explode('/', $_GET['path'])[0];
        }

        return '';
    }

    /**
     * Returns whether or not the current request is a GitHub ping event
     *
     * @return bool
     */
    public static function isGithubPing(): bool
    {
        return isset($_SERVER['HTTP_X_GITHUB_EVENT']) && $_SERVER['HTTP_X_GITHUB_EVENT'] == 'ping';
    }

    /**
     * Returns the name of the branch associated with the current request
     *
     * @return string Empty if there is no branch name
     */
    public static function getBranch(): string
    {
        $repoName = Request::getRepoName();
        $site = Site::getSite($repoName);
        $availableBranches = array_keys($site);
        if (empty($_POST)) {
            $path = $_GET['path'] ?? '';
            if (strpos($path, '/') === false) {
                header('HTTP/1.1 404 Not Found');
                echo 'No branch specified. Branches that can be auto-deployed: ' . implode(', ', $availableBranches);
                exit;
            }

            return explode('/', $path)[1];
        } elseif (isset($_POST['payload'])) {
            $payload = json_decode($_POST['payload']);

            return explode('/', (string)($payload->ref ?? ''))[2] ?? '';
        }

        return '';
    }

    /**
     * Returns a string describing what caused this deployment to happen
     *
     * @return string
     */
    public static function getDeployTrigger(): string
    {
        $branch = Request::getBranch();
        $repoName = Request::getRepoName();

        if (empty($_POST)) {
            $ip = Request::getIpAddress();
            $allowedIps = include dirname(dirname(__FILE__)) . '/config/allowed_ip_addresses.php';
            $requesterName = array_search($ip, $allowedIps);

            return "Deploy triggered manually by $requesterName for $branch branch of $repoName";
        }

        $payload = Request::getPayload();
        $pusher = $payload->pusher->name;
        $beforeSha = substr($payload->before, 0, 7);
        $afterSha = substr($payload->after, 0, 7);

        return "Push from $pusher updated head SHA of $branch branch of $repoName from $beforeSha to $afterSha";
    }

    /**
     * Returns the decoded payload sent by a GitHub webhook
     *
     * @return object
     */
    public static function getPayload(): object
    {
        if (isset($_POST['payload'])) {
            $payload = json_decode($_POST['payload']);

            return is_object($payload) ? $payload : new stdClass();
        }

        return new stdClass();
    }

    /**
     * Returns whether or not the current request was made by an authorized IP address
     *
     * @return bool
     */
    public static function isAuthorized(): bool
    {
        $allowedIps = include dirname(dirname(__FILE__)) . '/config/allowed_ip_addresses.php';
        $ip = $_SERVER['REMOTE_ADDR'];
        foreach ($allowedIps as $name => $allowedIp) {
            if (strpos($ip, $allowedIp) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns TRUE if $ip is found within $range
     *
     * Originally posted on https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5
     *
     * @param string $ip IP address
     * @param string $range IP address range in CIDR notation
     * @return bool
     */
    public static function cidrMatch(string $ip, string $range): bool
    {
        list ($subnet, $bits) = explode('/', $range);
        if ($bits === null) {
            $bits = 32;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnetLong &= $mask; // nb: in case the supplied subnet wasn't correctly aligned

        return ($ipLong & $mask) == $subnetLong;
    }

    /**
     * Returns TRUE if the current request is coming from a webhook IP address listed by GitHub's /meta API endpoint
     *
     * @throws \Exception
     * @returns bool
     */
    public static function isGitHub(): bool
    {
        // Get IP address ranges from GitHub
        $githubMetaUrl = 'https://api.github.com/meta';
        $ch = curl_init($githubMetaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_USERAGENT,'Phantom Deploy-bot');
        $curlResult = curl_exec($ch);
        $ipAddresses = json_decode($curlResult === false ? '' : $curlResult);
        if (!isset($ipAddresses->hooks)) {
            throw new Exception('Unable to retrieve GitHub webhook IP address ranges');
        }

        // Confirm that this request is coming from one of those ranges
        $ip = $_SERVER['REMOTE_ADDR'];
        foreach ($ipAddresses->hooks as $range) {
            if (self::cidrMatch($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
