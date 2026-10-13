<?php
declare(strict_types=1);

namespace App;

class Slack
{
    /**
     * @var int Rough max length for a message
     */
    const MAX_LENGTH = 39000;

    /**
     * @var int Number of times to try sending a message before giving up
     */
    const MAX_ATTEMPTS = 3;

    /**
     * @var int Longest time, in seconds, to wait before a retry
     */
    const MAX_RETRY_DELAY = 10;

    /**
     * @var string Marks the start and end of a code block
     */
    const FENCE = '```';

    /**
     * @var int Room to leave in a message for the newline and fence that close an open code block
     */
    const FENCE_RESERVE = 4;

    public $content;
    public $curlResult;

    public string $repoName;

    /**
     * Adds $line and a newline to the message being built
     *
     * @param string $line Line of text to add
     * @return void
     */
    public function addLine(string $line): void
    {
        $this->content .= $line . "\n";
    }

    /**
     * Transforms special characters in $text to make them Slack-friendly
     *
     * @param string $text Text to escape
     * @return string
     */
    public function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * Sends the current message to Slack, split into as many messages as required to avoid Slack's length limit
     *
     * Any part of the message that couldn't be sent is kept in $this->content so that the next call retries it.
     *
     * @return bool True if everything was sent
     */
    public function send(): bool
    {
        $chunks = $this->chunkContent((string)$this->content);
        $this->content = '';
        foreach ($chunks as $i => $chunk) {
            if (!$this->sendChunk($chunk)) {
                $this->content = implode("\n", array_slice($chunks, $i)) . "\n";

                return false;
            }
        }
        $this->curlResult = 'ok';

        return true;
    }

    /**
     * Sends one message to Slack, retrying if the failure looks temporary
     *
     * @param string $text Unescaped message text
     * @return bool
     */
    private function sendChunk(string $text): bool
    {
        $json = json_encode(
            [
                'channel' => '#deploy',
                'icon_emoji' => ':robot_face:',
                'text' => $this->escape($text),
                'username' => 'Phantom Deploy-bot',
            ],
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $this->curlResult = 'Unable to encode message as JSON';

            return false;
        }

        $url = $this->getWebhookUrl();
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->post($url, $json);
            if ($response['status'] == 200 && $response['body'] === 'ok') {
                return true;
            }

            $this->curlResult = $response['error'] ?: 'HTTP ' . $response['status'] . ': ' . $response['body'];
            $isTemporary = $response['error'] !== ''
                || $response['status'] == 429
                || $response['status'] >= 500;
            if (!$isTemporary || $attempt == self::MAX_ATTEMPTS) {
                return false;
            }

            $delay = 2 ** ($attempt - 1);
            if ($response['status'] == 429 && $response['retryAfter'] > 0) {
                $delay = $response['retryAfter'];
            }
            $this->pause(min($delay, self::MAX_RETRY_DELAY));
        }

        return false;
    }

    /**
     * Returns the Slack webhook URL for the current repo
     *
     * @return string
     */
    protected function getWebhookUrl(): string
    {
        $urls = include dirname(dirname(__FILE__)) . '/config/slack_webhook_urls.php';

        return $urls[$this->repoName ?? 'default'] ?? $urls['default'];
    }

    /**
     * POSTs a JSON string to $url
     *
     * @param string $url
     * @param string $json
     * @return array With keys body (string), error (string, empty if none), retryAfter (int seconds), status (int)
     */
    protected function post(string $url, string $json): array
    {
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => function (\CurlHandle $ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = (int)trim(substr($header, strlen('Retry-After:')));
                }

                return strlen($header);
            },
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);

        return [
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? curl_error($ch) : '',
            'retryAfter' => $retryAfter,
            'status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        ];
    }

    /**
     * Waits before retrying a request
     *
     * @param int $seconds
     * @return void
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Splits $content into messages that fit within Slack's length limit (after escaping), breaking between lines
     *
     * A code block that spans a break is closed at the end of one message and reopened at the start of the next.
     *
     * @param string $content Unescaped message
     * @return string[]
     */
    public function chunkContent(string $content): array
    {
        $limit = self::MAX_LENGTH - self::FENCE_RESERVE;
        $chunks = [];
        $lines = [];
        $size = 0;
        $inFence = false;
        foreach (explode("\n", $content) as $line) {
            $isFence = $line === self::FENCE;
            foreach ($this->splitLine($line, $limit - strlen(self::FENCE) - 1) as $piece) {
                $pieceSize = strlen($this->escape($piece)) + 1;
                $isClosingFence = $isFence && $inFence;
                if ($lines && !$isClosingFence && $size + $pieceSize > $limit) {
                    if ($inFence) {
                        $lines[] = self::FENCE;
                    }
                    $chunks[] = implode("\n", $lines);
                    $lines = $inFence ? [self::FENCE] : [];
                    $size = $inFence ? strlen(self::FENCE) + 1 : 0;
                }
                $lines[] = $piece;
                $size += $pieceSize;
            }
            if ($isFence) {
                $inFence = !$inFence;
            }
        }
        if ($lines) {
            $chunks[] = implode("\n", $lines);
        }

        return array_values(array_filter($chunks, fn(string $chunk): bool => trim($chunk) !== ''));
    }

    /**
     * Splits a single line that is too long (once escaped) into pieces that fit
     *
     * @param string $line
     * @param int $maxSize Maximum escaped size of a piece, including its newline
     * @return string[]
     */
    private function splitLine(string $line, int $maxSize): array
    {
        if (strlen($this->escape($line)) + 1 <= $maxSize) {
            return [$line];
        }

        // Escaping can expand a character to at most 5 bytes ('&' to '&amp;')
        $maxBytes = intdiv($maxSize - 1, 5);
        $pieces = [];
        for ($offset = 0; $offset < strlen($line);) {
            $piece = mb_strcut($line, $offset, $maxBytes);
            $pieces[] = $piece;
            $offset += strlen($piece);
        }

        return $pieces;
    }

    /**
     * Outputs the results of a command to the current message in an abbreviated way
     *
     * @param string $command Command being run
     * @param string $results Results of command
     * @return void
     */
    public function addAbridged(string $command, string $results): void
    {
        if ($command == 'git pull') {
            $keyStrings = [
                'Already up-to-date',
                ' changed,',
                'error:',
                'Fast-forward'
            ];
            foreach ($keyStrings as $keyString) {
                $this->addLinesWithString($results, $keyString, '*Git:* ');
            }

            return;
        }

        /* Special, cleaner versions of command output. Look nicer, but worse for debugging
        if (strpos($command, 'composer.phar self-update') !== false) {
            $this->addLine("*Composer self-update:*\n```\n$results\n```");
            return;
        }

        if (strpos($command, 'composer.phar install') !== false) {
            $this->addLine("*Composer install:*\n```\n$results\n```");
            return;
        }
        */

        /*
        if (strpos($command, 'composer.phar install') !== false) {
            $keyStrings = [
                'Nothing to install or update',
                'Updating',
                'Installing'
            ];
            foreach ($keyStrings as $keyString) {
                $this->addLinesWithString($results, $keyString, '*Composer:* ');
            }

            return;
        }
        */

        // Prevent backticks in the output from closing the code block early
        // (a zero-width space breaks up the backticks)
        $results = str_replace(self::FENCE, "``\u{200B}`", $results);
        $this->addLine("*$command:*\n" . self::FENCE . "\n$results\n" . self::FENCE);
    }

    /**
     * Adds any lines in $message that include $search
     *
     * @param string $message Full, multi-line message
     * @param string $search Search term
     * @param string $prefix Optional prefix for added line
     * @return void
     */
    public function addLinesWithString(string $message, string $search, string $prefix = ''): void
    {
        if (strpos($message, $search) === false) {
            return;
        }

        foreach (explode("\n", $message) as $line) {
            if (stripos($line, $search) !== false) {
                $line = $this->trimDetails($line);
                $this->addLine($prefix . trim($line));
            }
        }
    }

    /**
     * Removes unnecessary details from Composer output messages
     *
     * @param string $msg Message
     * @return string
     */
    public function trimDetails(string $msg): string
    {
        // Remove leading "- "
        if (substr($msg, 0, 2) == '- ') {
            $msg = substr($msg, 2);
        }

        // Remove "(Downloading X%)"
        while (stripos($msg, 'Downloading') !== false) {
            $start = stripos($msg, 'Downloading');
            $end = stripos($msg, ')', $start);
            if ($end === false) {
                break;
            }
            $substr = substr($msg, $start, $end - $start + 1);
            $msg = str_replace($substr, '', $msg);
        }

        // Remove "Checking out..."
        $checkingOut = strpos($msg, ': Checking out');
        if ($checkingOut !== false) {
            $msg = substr($msg, 0, $checkingOut);
        }

        // Remove other strings
        $removeStrings = [
            ': Loading from cache'
        ];
        foreach ($removeStrings as $removeString) {
            $msg = str_replace($removeString, '', $msg);
        }

        $msg = trim($msg);

        return $msg;
    }

    /**
     * Adds a message explaining what triggered this deployment, with the important bit bolded
     *
     * @param string $msg Message to add
     * @return void
     */
    public function addTriggerMsg(string $msg): void
    {
        if (strpos($msg, 'Push from') === 0) {
            $pos = strpos($msg, ' updated ');
            $msg = '*' . substr($msg, 0, $pos) . '*' . substr($msg, $pos);
        } elseif (strpos($msg, 'Deploy triggered manually') === 0) {
            $pos = strpos($msg, ' for ');
            $msg = '*' . substr($msg, 0, $pos) . '*' . substr($msg, $pos);
        }

        $this->addLine($msg);
    }
}
