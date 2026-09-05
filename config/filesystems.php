<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
         * Where every private document (results certificates, transcripts,
         * provider registration certificates, application attachments) actually
         * lives - see App\Services\FileStorageService::DISK.
         *
         * Defaults to storage_path('app'), exactly as before, so local
         * development, the test suite, and the existing VPS Docker Compose
         * stack (which bind-mounts ./storage/app) are unaffected by this
         * setting existing at all.
         *
         * FILESYSTEM_ROOT lets a deployment point this disk at a mounted
         * volume instead - a Render Persistent Disk, for example - without
         * touching a line of application code. Every path recorded in the
         * database (document_files.path, applicant_profiles.*_path,
         * provider_profiles.certificate_path, applications.document_path) is
         * relative to this root, never absolute, so redirecting the root does
         * not invalidate a single existing row - it only changes where the
         * bytes those rows describe are read from and written to. Moving the
         * bytes themselves to the new root is a separate, one-time step; see
         * docs/DEPLOYMENT.md.
         *
         * Left untouched: the "public" disk below and everything cache,
         * session and log related, which all resolve through storage_path()
         * directly rather than this config value - see config/cache.php,
         * config/session.php and config/logging.php. Setting FILESYSTEM_ROOT
         * moves only the document disk.
         */
        'local' => [
            'driver' => 'local',
            'root' => env('FILESYSTEM_ROOT', storage_path('app')),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
