<?php
/**
 * Information about sites that are eligible for automatic deployment
 *
 * Format:
 * [
 *      'repo-name' => [
 *          'branch-name' => [
 *              'dir' => 'corresponding subdirectory of home directory'
 *              'url' => 'URL for corresponding production/staging site'
 *              'commands' => array of commands to use instead of ['repo-name']['commands']
 *          ],
 *          ...
 *          'commands' => [
 *              'command', // e.g. 'gulp less'
 *              ...
 *          ]
 *      ]
 * ]
 */

$php = '/usr/local/bin/php'; // PHP 8.5
$composer = "/home/phanto41/public_html/deploybot/composer.phar";
$setupComposer = 'export COMPOSER_HOME="~/.config/composer/"';

$php81 = '/usr/local/bin/ea-php81';
$runComposerPhp81 = "$php81 $composer self-update; $php81 $composer install --no-dev";

$php82 = '/usr/local/bin/ea-php82';
$runComposerPhp82 = "$php82 $composer self-update; $php82 $composer install --no-dev";

$runComposerPhp85 = "$php $composer self-update; $php $composer install --no-dev";

$cake4CacheClear = "$php bin/cake.php schema_cache build --connection default";
$cake5CacheClear = $cake4CacheClear;
$migrate = "$php bin/cake.php migrations migrate";
$npm = '/opt/cpanel/ea-nodejs22/bin/npm';
$updateReactApp = "$npm install && $npm run webpack -- --env mode=production";
$pull = 'git pull; git status';

return [
    'deploybot' => [
        'master' => [
            'dir' => 'public_html/deploybot',
            'php' => 8,
        ],
        'commands' => [
            $pull,
            $setupComposer,
            $runComposerPhp85,
        ],
    ],
    'vore-arts-fund' => [
        'master' => [
            'dir' => 'public_html/vore',
            'php' => 8,
        ],
        'development' => [
            'dir' => 'public_html/vore_staging',
            'php' => 8,
        ],
        'commands' => [
            $pull,
            $setupComposer,
            $runComposerPhp85,
            $migrate,
            $cake4CacheClear,
            "cd ./config/webpack && $npm install; cd ../..",
            "cd ./webroot/vote-app && $updateReactApp; cd ../..",
            "cd ./webroot/image-uploader && $updateReactApp; cd ../..",
            "cd ./webroot/rich-text-editor && $npm install && $npm run build; cd ../..",
            "cd ./webroot/transaction-form && $npm install && $npm run build; cd ../..",
            "cd ./webroot/repayment-form && $npm install && $npm run build; cd ../..",
        ],
    ],
    'muncie-events-api' => [
        'master' => [
            'dir' => 'muncie_events5',
            'php' => 8,
        ],
        'development' => [
            'dir' => 'muncie_events_staging',
            'php' => 8,
        ],
        'commands' => [
            $pull,
            $setupComposer,
            $runComposerPhp81,
            $migrate,
            $cake5CacheClear,
        ],
    ],
    'sumner-phone' => [
        'master' => [
            'dir' => 'public_html/sumner-phone',
            'php' => 8, // 8.2
        ],
        'commands' => [
            $pull,
            $setupComposer,
            $runComposerPhp82,
            $migrate,
            $cake4CacheClear,
            "cd ./webroot/menu-builder && $npm install && $npm run build; cd ../..",
        ],
    ],
];
