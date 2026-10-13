<?php
declare(strict_types=1);

    $appDir = dirname(dirname(__FILE__));
    spl_autoload_register(function (string $className) use ($appDir): void {
        $className = str_replace('App\\', '', $className);
        include $appDir . '/src/' . $className . '.php';
    });
    $siteName = is_string($_GET['site'] ?? null) ? $_GET['site'] : '';
    echo App\Site::isValid($siteName) ? 1 : 0;
