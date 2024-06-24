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
$updateReactApp = "$npm install && $npm run webpack -- --env mode=production";

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
            "cd ./config/webpack && $npm install; cd ../..",
            "cd ./webroot/review && $updateReactApp; cd ../..",
            "cd ./webroot/vote-app && $updateReactApp; cd ../..",
            "cd ./webroot/image-uploader && $updateReactApp; cd ../..",
        ],
    ],
    'muncie-events-api' => [
        'master' => [
            'dir' => 'muncie_events4',
            'php' => 8,
            'commands' => [
                $migrate,
                $cake4CacheClear,
            ],
        ],
        'development' => [
            'dir' => 'muncie_events4_staging',
            'php' => 8,
            'commands' => [
                $migrate,
                $cake4CacheClear,
            ],
        ],
    ],
];
