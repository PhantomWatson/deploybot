<?php
/**
 * A list of all commands to run via shell_exec() for each deployment
 */

$php = '/usr/local/bin/php';
$composer = "$php /home/phanto41/public_html/deploybot/composer.phar";

return [
    'whoami',
    'git pull; git status',
    "export COMPOSER_HOME=\"~/.config/composer/\" && $composer self-update; $composer install --no-dev",
];
