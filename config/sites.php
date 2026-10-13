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
 *              [
 *                  // A command that only runs if something has changed since it last succeeded (see $build below)
 *                  'command' => 'command',
 *                  'key' => 'optional unique name for this step (default: the command)',
 *                  'outputs' => ['optional paths that the command produces. If any is missing, it always runs'],
 *                  'paths' => ['paths in the repository that the command depends on'],
 *              ],
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

/**
 * Returns a command that runs in a subdirectory of the site and is skipped if nothing in that directory has changed
 * since it last succeeded
 *
 * @param string $dir Subdirectory, relative to the site's root
 * @param string $command Command to run in that directory
 * @param string[] $dependsOn Other paths in the repository whose changes also require running the command
 * @return array
 */
$build = fn(string $dir, string $command, array $dependsOn = []): array => [
    'command' => "cd ./$dir && $command",
    'key' => $dir,
    'paths' => [$dir, ...$dependsOn],
];

return [
    'deploybot' => [
        'master' => [
            'dir' => 'public_html/deploybot',
            'php' => 8,
        ],
        'commands' => [
            'git pull',
            'git status',
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
            'git pull',
            'git status',
            $setupComposer,
            $runComposerPhp85,
            $migrate,
            $cake4CacheClear,
            $build('config/webpack', "$npm install"),
            $build('webroot/vote-app', $updateReactApp, ['config/webpack']),
            $build('webroot/image-uploader', $updateReactApp, ['config/webpack']),
            $build('webroot/rich-text-editor', "$npm install && $npm run build", ['config/webpack']),
            $build('webroot/transaction-form', "$npm install && $npm run build", ['config/webpack']),
            $build('webroot/repayment-form', "$npm install && $npm run build", ['config/webpack']),
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
            'git pull',
            'git status',
            $setupComposer,
            $runComposerPhp85,
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
            'git pull',
            'git status',
            $setupComposer,
            $runComposerPhp82,
            $migrate,
            $cake4CacheClear,
            $build('webroot/menu-builder', "$npm install && $npm run build"),
        ],
    ],
    'ether' => [
        'master' => [
            'dir' => 'public_html/ether',
            'php' => 8, // 8.5
        ],
        'dev' => [
            'dir' => 'ether_staging',
            'php' => 8, // 8.5
        ],
        'commands' => [
            'git pull',
            'git status',
            $setupComposer,
            $runComposerPhp85,
            $migrate,
            $cake5CacheClear,
        ],
    ],
];
