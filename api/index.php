<?php

$storagePath = sys_get_temp_dir().'/laravel-storage';

foreach ([
    'APP_CONFIG_CACHE',
    'APP_EVENTS_CACHE',
    'APP_PACKAGES_CACHE',
    'APP_ROUTES_CACHE',
    'APP_SERVICES_CACHE',
] as $key) {
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

foreach ([
    $storagePath,
    $storagePath.'/app',
    $storagePath.'/app/public',
    $storagePath.'/framework',
    $storagePath.'/framework/cache',
    $storagePath.'/framework/cache/data',
    $storagePath.'/framework/sessions',
    $storagePath.'/framework/views',
    $storagePath.'/logs',
] as $path) {
    if (! file_exists($path)) {
        mkdir($path, 0777, true);
    }
}

foreach ([
    'LARAVEL_STORAGE_PATH' => $storagePath,
    'VIEW_COMPILED_PATH' => $storagePath.'/framework/views',
] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../public/index.php';
