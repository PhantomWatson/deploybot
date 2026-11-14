<?php
declare(strict_types=1);

/**
 * Runs a deployment in the background. Started by App\Webhook.
 *
 * Usage: php deploy-worker.php <path of job file>
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$appDir = dirname(__DIR__);
spl_autoload_register(function (string $className) use ($appDir): void {
    $className = str_replace('App\\', '', $className);
    include $appDir . '/src/' . $className . '.php';
});

// Builds can take a long time
set_time_limit(0);

$jobPath = $argv[1] ?? '';

// Leave a trace as early as possible, so that a worker that is later killed silently can be told apart from one that
// never ran
@file_put_contents(
    $appDir . '/logs/worker-errors.log',
    sprintf("[%s] Worker started (pid %d) for %s\n", date('Y-m-d H:i:s'), getmypid(), basename($jobPath)),
    FILE_APPEND
);

$job = is_file($jobPath) ? json_decode((string)file_get_contents($jobPath), true) : null;
if (is_file($jobPath)) {
    unlink($jobPath);
}
if (!is_array($job) || !isset($job['repo'], $job['branch'], $job['trigger'])) {
    fwrite(STDERR, "Invalid or missing job file: $jobPath\n");
    exit(1);
}

$startSlack = new App\Slack();
$startSlack->repoName = (string)$job['repo'];
$startSlack->addLine("Deployment of the {$job['branch']} branch of {$job['repo']} started (worker pid " . getmypid() . ')');
$startSlack->send();

$deploy = new App\Deploy((string)$job['repo'], (string)$job['branch'], (string)$job['trigger']);
register_shutdown_function([$deploy, 'reportFatalError']);
$deploy->run();
