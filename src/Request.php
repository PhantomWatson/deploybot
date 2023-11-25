<?php
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
    public static function getIpAddress()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Returns the name of the GitHub repository associated with the current request
     *
     * @return string|bool
     */
    public static function getRepoName()
    {
        if (isset($_POST['payload'])) {
            $payload = json_decode($_POST['payload']);
            if (isset($payload->repository->name)) {
                return $payload->repository->name;
            }

            return false;
        }

        if (isset($_GET['path'])) {
            return explode('/', $_GET['path'])[0];
        }

        return false;
    }

    /**
     * Returns whether or not the current request is a GitHub ping event
     *
     * @return bool
     */
    public static function isGithubPing()
    {
        return isset($_SERVER['HTTP_X_GITHUB_EVENT']) && $_SERVER['HTTP_X_GITHUB_EVENT'] == 'ping';
    }

    /**
     * Returns the name of the branch associated with the current request
     *
     * @return string|bool
     */
    public static function getBranch()
    {
        $repoName = Request::getRepoName();
        $site = Site::getSite($repoName);
        $availableBranches = array_keys($site);
        if (empty($_POST)) {
            if (strpos($_GET['path'], '/') === false) {
                header('HTTP/1.1 404 Not Found');
                echo 'No branch specified. Branches that can be auto-deployed: ' . implode(', ', $availableBranches);
                exit;
            }

            return explode('/', $_GET['path'])[1];
        } elseif (isset($_POST['payload'])) {
            $payload = json_decode($_POST['payload']);

            return explode('/', $payload->ref)[2];
        }

        return false;
    }

    /**
     * Returns a string describing what caused this deployment to happen
     *
     * @return string
     */
    public static function getDeployTrigger()
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
     * @return mixed|stdClass
     */
    public static function getPayload()
    {
        if (isset($_POST['payload'])) {
            return json_decode($_POST['payload']);
        }

        return new stdClass();
    }

    /**
     * Returns whether or not the current request was made by an authorized IP address
     *
     * @return bool
     */
    public static function isAuthorized()
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
    public static function cidrMatch($ip, $range)
    {
        list ($subnet, $bits) = explode('/', $range);
        if ($bits === null) {
            $bits = 32;
        }
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask; // nb: in case the supplied subnet wasn't correctly aligned

        return ($ip & $mask) == $subnet;
    }

    /**
     * Returns TRUE if the current request is coming from a webhook IP address listed by GitHub's /meta API endpoint
     *
     * @throws \Exception
     * @returns bool
     */
    public static function isGitHub()
    {
        // Get IP address ranges from GitHub
        $githubMetaUrl = 'https://api.github.com/meta';
        $ch = curl_init($githubMetaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_USERAGENT,'CBER Deploy-bot');
        $curlResult = curl_exec($ch);
        curl_close($ch);
        $ipAddresses = json_decode($curlResult);
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
