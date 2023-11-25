<?php
namespace App;

class Site
{
    /**
     * Returns whether or not $repoName is listed in sites.php
     *
     * @param string $repoName Name of GitHub repository
     * @return bool
     */
    public static function isValid($repoName)
    {
        $sites = include dirname(dirname(__FILE__)) . '/config/sites.php';

        return isset($sites[$repoName]);
    }

    /**
     * Returns the array of information stored in /config/sites.php about the specified site
     *
     * @param string $repoName Name of GitHub repository
     * @return array
     */
    public static function getSite($repoName)
    {
        $sites = self::getSites();

        return $sites[$repoName];
    }

    /**
     * Returns the array stored in /config/sites.php
     *
     * @return array
     */
    public static function getSites()
    {
        return include dirname(dirname(__FILE__)) . '/config/sites.php';
    }

    /**
     * Returns whether or not the specified branch is recognized for the specified site
     *
     * @param string $branch Branch name
     * @param array $site Array of information about a site
     * @return bool
     */
    public static function isValidBranch($branch, $site)
    {
        $availableBranches = Site::getAvailableBranches($site);

        return in_array($branch, $availableBranches);
    }

    /**
     * Returns the branches associated with a site in /config/sites.php
     *
     * @param array $site Array of information about a site
     * @return array
     */
    public static function getAvailableBranches($site)
    {
        if (isset($site['commands'])) {
            unset($site['commands']);
        }

        return array_keys($site);
    }
}
