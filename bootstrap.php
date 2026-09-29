<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Neo\\')) {
            require __DIR__ . '/src/' . substr($class, 4) . '.php';
        }
    });
}
\Neo\Config::load(__DIR__);
\Neo\Config::basePath();
foreach (['pdo_sqlite', 'dom', 'fileinfo'] as $extension) {
    if (!extension_loaded($extension)) {
        http_response_code(503);
        exit('Enable the PHP '.$extension.' extension in your hosting control panel.');
    }
}
if (\Neo\Config::shared() && !class_exists('getID3')) {
    http_response_code(503);
    exit('Missing MP3 parser. Upload the complete release including its vendor directory.');
}
date_default_timezone_set('UTC');
return new Neo\Store(\Neo\Config::get('NEO_DATA') ?: __DIR__ . '/var');
