<?php
/**
 * Information about sites that are eligible for automatic deployment
 *
 * Format:
 * [
 *      'repo-name' => [
 *          'branch-name' => [
 *              'dir' => 'corresponding subdirectory of public_html'
 *              'url' => 'URL for corresponding production/staging site'
 *          ],
 *          ...
 *          'commands' => [
 *              'command', // e.g. 'gulp less'
 *              ...
 *          ]
 *      ]
 * ]
 */

$php = '/usr/local/bin/php';
$cake3CacheClear = "$php bin/cake.php orm_cache clear";
$cake4CacheClear = "$php bin/cake.php schema_cache build --connection default";
$migrate = "$php bin/cake.php migrations migrate";
$npm = '/opt/cpanel/ea-nodejs16/bin/npm';

return [
    'deploybot' => [
        'master' => [
            'dir' => 'deploybot',
            'php' => 8,
        ]
    ],
    'vore-arts-fund' => [
        'master' => [
            'dir' => 'vore',
            'php' => 8,
        ],
        'development' => [
            'dir' => 'vore_staging',
            'php' => 8,
        ],
        'commands' => [
            $migrate,
            $cake4CacheClear,
            "$npm install --prefix ./webroot/review",
            "$npm run prod --prefix ./webroot/review",
            "$npm install --prefix ./webroot/review",
            "$npm run prod --prefix ./webroot/vote-app",
        ],
    ],
];
