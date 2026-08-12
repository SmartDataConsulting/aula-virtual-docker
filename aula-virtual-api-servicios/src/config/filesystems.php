<?php

return [

    'default' => env('FILESYSTEM_DISK', 'files'),

    'disks' => [

        'files' => [
            'driver' => 'local',
            'root' => env('FILES_ROOT', storage_path('app/files')),
            'visibility' => 'private',
        ],

    ],

];