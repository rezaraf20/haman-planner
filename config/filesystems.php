<?php
return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => true, 'throw' => false],
        // Private attachments. Mount storage/app as a persistent volume in Docker (see docs/deployment.md).
        'attachments' => ['driver' => 'local', 'root' => storage_path('app/attachments'), 'serve' => false, 'throw' => true],
        'public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false],
    ],
    'links' => [public_path('storage') => storage_path('app/public')],
];
