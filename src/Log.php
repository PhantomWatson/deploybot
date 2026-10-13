<?php
declare(strict_types=1);

namespace App;

class Log
{
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
     * Writes the current log entry to the specified repository's log file
     *
     * @param string $repoName Name of GitHub repository
     * @return void
     */
    public function write(string $repoName): void
    {
        $this->content .= '<hr />';
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents("$dir/$repoName.html", $this->content, FILE_APPEND | LOCK_EX);
    }
}
