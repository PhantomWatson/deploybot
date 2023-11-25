<?php
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
    public function addLine($line)
    {
        $this->content .= nl2br($line) . "<br />\n";
    }

    /**
     * Writes the current log entry to a log file
     *
     * @return void
     */
    public function write()
    {
        $this->content .= '<hr />';
        $repoName = Request::getRepoName();
        $filename = dirname(dirname(__FILE__)) . "/logs/$repoName.html";
        file_put_contents($filename, $this->content, FILE_APPEND);
    }
}
