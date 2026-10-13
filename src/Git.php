<?php
declare(strict_types=1);

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
    public static function canPull(string $repoName, string $branch): bool
    {
        if (!Site::isValid($repoName)) {
            throw new Exception('Unrecognized repo: ' . $repoName);
        }

        $site = Site::getSite($repoName);
        if (!Site::isValidBranch($branch, $site)) {
            throw new Exception("$branch branch does not exist for $repoName repo");
        }

        // Make sure site directory exists
        $sitesRoot = Config::get('sitesRoot');
        $siteDir = $sitesRoot . '/' . $site[$branch]['dir'];
        if (!file_exists($siteDir)) {
            return false;
        }

        // Change working directory to appropriate website
        chdir($siteDir);

        // Check to see if status mentions modified files
        $process = proc_open(
            'git status',
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );
        $results = is_resource($process) ? (stream_get_contents($pipes[1]) ?: '') : '';

        return !stripos($results, 'modified: ');
    }

    /**
     * Returns the SHA of the current commit in the current working directory's repository, or NULL if unavailable
     *
     * @return string|null
     */
    public static function head(): ?string
    {
        [$output, $exitCode] = self::run(['rev-parse', 'HEAD']);
        $sha = trim($output);

        return $exitCode === 0 && self::isSha($sha) ? $sha : null;
    }

    /**
     * Returns whether or not the current working directory's repository contains the specified commit
     *
     * @param string $sha Commit SHA
     * @return bool
     */
    public static function hasCommit(string $sha): bool
    {
        if (!self::isSha($sha)) {
            return false;
        }

        [, $exitCode] = self::run(['cat-file', '-e', $sha . '^{commit}']);

        return $exitCode === 0;
    }

    /**
     * Returns TRUE only if it can be confirmed that nothing under $paths differs between commit $sha and HEAD
     *
     * Any failure to find out (e.g. $sha no longer exists) returns FALSE.
     *
     * @param string $sha Commit SHA
     * @param string[] $paths Paths, relative to the repository root
     * @return bool
     */
    public static function unchangedSince(string $sha, array $paths): bool
    {
        if (!self::isSha($sha) || !$paths) {
            return false;
        }

        // Exit code is 0 if there are no differences, 1 if there are, and something else for errors
        [, $exitCode] = self::run(['diff', '--quiet', $sha, 'HEAD', '--', ...$paths]);

        return $exitCode === 0;
    }

    /**
     * Returns whether or not $value is a full-length commit SHA
     *
     * @param string $value
     * @return bool
     */
    private static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    /**
     * Runs a git command in the current working directory
     *
     * @param string[] $args Arguments for git. These are not interpreted by a shell.
     * @return array{0: string, 1: int} Output (stdout and stderr) and exit code
     */
    private static function run(array $args): array
    {
        $process = proc_open(
            ['git', ...$args],
            [
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            return ['', -1];
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [$output === false ? '' : $output, proc_close($process)];
    }
}
