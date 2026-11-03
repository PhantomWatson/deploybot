<?php
declare(strict_types=1);

$appDir = dirname(dirname(__FILE__));
if (isset($_GET['site'])) {
    $siteName = $_GET['site'];
    $path = $appDir . '/logs/' . $siteName . '.html';
    if (file_exists($path)) {
        include $path;
    } else {
        echo 'Log file not found';
    }
} else {
    echo 'No site name provided';
}
