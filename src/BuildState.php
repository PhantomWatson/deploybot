<?php
declare(strict_types=1);

namespace App;

/**
 * Remembers the commit at which each build step last succeeded, so that unchanged builds can be skipped
 */
class BuildState
{
    private ?array $state = null;

    /**
     * BuildState constructor
     *
     * @param string $path Path of the JSON file in which state is stored
     */
    public function __construct(private string $path)
    {
    }

    /**
     * Returns the commit SHA at which $key last succeeded, or NULL if it never has
     *
     * @param string $key Identifies a build step
     * @return string|null
     */
    public function get(string $key): ?string
    {
        $sha = $this->load()[$key] ?? null;

        return is_string($sha) ? $sha : null;
    }

    /**
     * Records that $key succeeded at commit $sha
     *
     * @param string $key Identifies a build step
     * @param string $sha Commit SHA
     * @return void
     */
    public function set(string $key, string $sha): void
    {
        $state = $this->load();
        $state[$key] = $sha;
        ksort($state);
        $this->state = $state;

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        // Write to a temporary file first so that the state file is never left half-written
        $tempPath = $this->path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tempPath, json_encode($state, JSON_PRETTY_PRINT)) !== false) {
            rename($tempPath, $this->path);
        }
    }

    /**
     * Returns the stored state, reading it from the file if that hasn't been done yet
     *
     * @return array
     */
    private function load(): array
    {
        if ($this->state === null) {
            $contents = file_exists($this->path) ? file_get_contents($this->path) : false;
            $decoded = $contents === false ? null : json_decode($contents, true);
            $this->state = is_array($decoded) ? $decoded : [];
        }

        return $this->state;
    }
}
