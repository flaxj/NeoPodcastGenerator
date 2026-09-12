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
date_default_timezone_set('UTC');
return new Neo\Store(getenv('NEO_DATA') ?: __DIR__ . '/var');
