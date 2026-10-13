<?php
/**
 * Settings for this installation
 *
 * Values can be overridden for a particular environment (e.g. local development) by returning a partial array from
 * config/app.local.php, which is not tracked by git.
 */
return [
    // Command-line PHP binary used to run background deployments
    'php' => '/usr/local/bin/php',

    // Directory containing the sites' directories (see 'dir' in sites.php)
    'sitesRoot' => '/home/username',
];
