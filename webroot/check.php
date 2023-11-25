<?php
    $appDir = dirname(dirname(__FILE__));
    spl_autoload_register(function ($className) use ($appDir) {
        $className = str_replace('App\\', '', $className);
        include $appDir . '/src/' . $className . '.php';
    });
    $siteName = isset($_GET['site']) ? $_GET['site'] : null;
    echo App\Site::isValid($siteName) ? 1 : 0;
