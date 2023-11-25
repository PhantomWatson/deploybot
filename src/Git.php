<?php
namespace App;

use Exception;

class Git
{
    /**
     * Returns TRUE if the site can pull changes from GitHub, or FALSE if modified files prevent this
     *
     * @param string $repoName Repository name
     * @param string $branch Branch name
     * @return bool
     * @throws Exception
     */
    public static function canPull($repoName, $branch)
    {
        if (!Site::isValid($repoName)) {
            throw new Exception('Unrecognized repo: ' . $repoName);
        }

        $site = Site::getSite($repoName);
        if (!Site::isValidBranch($branch, $site)) {
            throw new Exception("$branch branch does not exist for $repoName repo");
        }

        // Make sure site directory exists
        $appDir = dirname(dirname(__FILE__));
        $sitesRoot = dirname($appDir);
        $siteDir = $sitesRoot . '/' . $site[$branch]['dir'];
        if (!file_exists($siteDir)) {
            return false;
        }

        $sitesRoot = dirname($appDir);
        $siteDir = $sitesRoot . '/' . $site[$branch]['dir'];

        // Change working directory to appropriate website
        chdir($siteDir);

        // Check to see if status mentions modified files
        $results = shell_exec('git status');

        return !stripos($results, 'modified: ');
    }
}
