<?php
/**
 * A list of all commands to run via shell_exec() for each deployment
 */

$composer = 'php /home/phanto41/public_html/deploybot/composer.phar';

return [
    'git pull',
    'git status',
    "$composer self-update",
    "$composer install --no-dev",
];
