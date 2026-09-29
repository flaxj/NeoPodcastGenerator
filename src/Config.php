<?php

declare(strict_types=1);

namespace Neo;

final class Config
{
    private static array $values = [];

    public static function load(string $root): void
    {
        $defaults = is_file($root.'/defaults.php') ? require $root.'/defaults.php' : [];
        $local = is_file($root.'/config.php') ? require $root.'/config.php' : [];
        if (!is_array($local) || !is_array($defaults)) {
            throw new \RuntimeException('Configuration must return a PHP array.');
        }
        self::$values = $local + $defaults;
        if (!in_array(self::get('NEO_MODE', 'server'), ['shared', 'server'], true)) {
            throw new \RuntimeException('NEO_MODE must be shared or server.');
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? (string)(self::$values[$key] ?? $default) : $value;
    }

    public static function shared(): bool
    {
        return self::get('NEO_MODE', 'server') === 'shared';
    }

    public static function basePath(): string
    {
        $path = rtrim(self::get('NEO_BASE_PATH'), '/');
        if ($path !== '' && !preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $path)) {
            throw new \RuntimeException('NEO_BASE_PATH must be empty or a path such as /podcast.');
        }
        return $path;
    }

    public static function url(string $path): string
    {
        return self::basePath().$path;
    }

    public static function chunkSize(): int
    {
        return self::shared() ? 1048576 : 8388608;
    }
}
