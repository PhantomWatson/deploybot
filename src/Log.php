<?php
declare(strict_types=1);

namespace App;

class Log
{
    /**
     * @var string Ends each entry in a log file
     */
    const ENTRY_END = '<hr />';

    /**
     * @var int Size, in bytes, that a log file is trimmed to stay under (unless its newest entry is larger)
     */
    const MAX_SIZE = 1048576;

    public $content = '';
    public $entryId;

    /**
     * Log constructor
     */
    public function __construct()
    {
        $this->entryId = date('YmdHis');
        $this->content .= '<h1 id="' . $this->entryId . '">####### ' . date('Y-m-d H:i:s') . " #######</h1>\n";
    }

    /**
     * Adds a line to the current log entry
     *
     * @param string $line Line to add
     * @return void
     */
    public function addLine(string $line): void
    {
        $this->content .= nl2br($line) . "<br />\n";
    }

    /**
     * Adds preformatted HTML (e.g. a deployment's screen output) to the current log entry
     *
     * @param string $html HTML to add
     * @return void
     */
    public function addHtml(string $html): void
    {
        $this->content .= "<pre>$html</pre>\n";
    }

    /**
     * Writes the current log entry to the specified repository's log file, then removes the oldest entries if the file
     * has grown larger than MAX_SIZE
     *
     * @param string $repoName Name of GitHub repository
     * @return void
     */
    public function write(string $repoName): void
    {
        $this->content .= self::ENTRY_END;
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $handle = fopen("$dir/$repoName.html", 'c+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        fseek($handle, 0, SEEK_END);
        fwrite($handle, $this->content);
        $this->trim($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Removes the oldest entries from a log file that is larger than MAX_SIZE
     *
     * Whole entries are removed, so that the file never starts partway through one. The entry that was just written is
     * always kept.
     *
     * @param resource $handle Handle of the log file, which must be locked and have the newest entry at its end
     * @return void
     */
    private function trim($handle): void
    {
        rewind($handle);
        $data = stream_get_contents($handle);
        if ($data === false || strlen($data) <= self::MAX_SIZE) {
            return;
        }

        $newestStart = strlen($data) - strlen($this->content);
        $boundary = strpos($data, self::ENTRY_END, strlen($data) - self::MAX_SIZE);
        $start = $boundary === false ? $newestStart : min($boundary + strlen(self::ENTRY_END), $newestStart);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, substr($data, $start));
    }
}
