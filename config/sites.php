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

$cake3CacheClear = 'php bin/cake.php orm_cache clear';
$cake4CacheClear = 'php bin/cake.php schema_cache build --connection default';
$migrate = 'php bin/cake.php migrations migrate';

return [
    'deploy' => [
        'master' => [
            'dir' => 'deploy',
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
        'commands' => [$cake4CacheClear, $migrate],
    ],
];
