<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

class Config
{
    /**
     * Returns the value of a setting from config/app.php, as overridden by config/app.local.php
     *
     * @param string $key Name of setting
     * @return mixed
     * @throws RuntimeException
     */
    public static function get(string $key): mixed
    {
        $configDir = dirname(__DIR__) . '/config';
        $settings = include "$configDir/app.php";
        if (file_exists("$configDir/app.local.php")) {
            $settings = array_merge($settings, include "$configDir/app.local.php");
        }

        if (!array_key_exists($key, $settings)) {
            throw new RuntimeException("Unknown setting: $key");
        }

        return $settings[$key];
    }
}
