<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Uploads Disk
    |--------------------------------------------------------------------------
    |
    | Where files uploaded through the panel are kept: the logo, the favicon
    | and user avatars, and the file Livewire holds between the request that
    | uploads it and the one that reads it.
    |
    | On one machine that can be the local disk under public/uploads, which
    | needs no symlink and no external service. A serverless host has neither:
    | its filesystem is read-only and its temporary directory belongs to one
    | invocation, so a file written by the upload request is not there for the
    | request that stores it. Point this at "s3" there - any S3-compatible
    | bucket, Cloudflare R2 included - and both halves of an upload see the
    | same file.
    |
    | @see \App\Support\PublicUploads
    |
    */

    'uploads_disk' => env('UPLOADS_DISK', 'uploads'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Images uploaded through the panel: avatars and the branding.
         *
         * Rooted in public/ rather than storage/ because the server this is
         * deployed to has no terminal, so `php artisan storage:link` is never
         * run there and anything behind that symlink is unreachable. The URL
         * is root-relative for the same reason APP_URL cannot be trusted to
         * match the host the panel is actually being browsed at.
         */
        'uploads' => [
            'driver' => 'local',
            'root' => public_path('uploads'),
            'url' => '/uploads',
            'visibility' => 'public',
            'throw' => false,
            // A write that fails is reported. It still returns false rather
            // than raising - a missing image must not cost the page it sits
            // on - but silence was worse: an upload rejected by the bucket
            // reached the operator as "failed to upload" and reached the log
            // as nothing at all, leaving no way to find out why.
            'report' => true,
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
            // Reported for the reason given on the uploads disk above: a
            // bucket that refuses a write has a reason, and it is the only
            // thing that says why an upload failed.
            'report' => true,
        ],

        /*
         * The same bucket as the s3 disk, written by the application rather
         * than by the browser.
         *
         * The driver name is the whole difference. Livewire reads it to decide
         * how an upload travels: on "s3" it hands the browser a pre-signed URL
         * and the file goes straight to the bucket. Point this at a bucket
         * that only accepts writes from a server - Supabase Storage, whose S3
         * credentials are server-side only - and that upload fails out in the
         * browser, with nothing about it reaching the server log.
         *
         * @see \App\Providers\AppServiceProvider::registerServerSideBucketDriver()
         */
        'bucket' => [
            'driver' => 's3-server-side',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => true,
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
