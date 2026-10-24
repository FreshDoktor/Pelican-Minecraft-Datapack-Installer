<?php

return [
    // Largest datapack (in MB) that will be installed.
    'max_datapack_size_mb' => env('MODRINTH_DATAPACK_MAX_SIZE_MB', 100),

    // Hosts datapacks may be downloaded from.
    'allowed_download_hosts' => [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ],
];
