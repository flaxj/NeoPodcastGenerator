<?php

declare(strict_types=1);

// Build tooling only; not included in the upload-ready archive.
$root = dirname(__DIR__);
date_default_timezone_set('UTC');
// libzip uses the C runtime timezone when writing DOS timestamps.
putenv('TZ=UTC');
$version = $argv[1] ?? '';
if (!preg_match('/^v?\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $version)) {
    throw new RuntimeException('Usage: php bin/package.php <version>');
}
if (!class_exists(ZipArchive::class) || !is_file($root.'/vendor/autoload.php')) {
    throw new RuntimeException('Install production dependencies and enable PHP ZipArchive before packaging.');
}
$read = static function (string $relative) use ($root): string {
    $path = $root;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Unsafe package input: '.$relative);
        }
        $path .= '/'.$part;
        if (is_link($path)) {
            throw new RuntimeException('Linked package input: '.$relative);
        }
    }
    if (!is_file($path) || ($content = file_get_contents($path)) === false) {
        throw new RuntimeException('Missing or unreadable package input: '.$relative);
    }
    // Every allowlisted input is text. Normalize Windows checkouts for release builds.
    return str_replace("\r\n", "\n", $content);
};
$lock = json_decode($read('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$installed = json_decode($read('vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
$locked = $lock['packages'][0] ?? [];
$actual = $installed['packages'][0] ?? [];
if (count($lock['packages']) !== 1 || count($installed['packages']) !== 1
    || ($locked['name'] ?? '') !== 'james-heinrich/getid3'
    || ($actual['name'] ?? '') !== $locked['name']
    || ($actual['version'] ?? '') !== $locked['version']
    || ($actual['source']['reference'] ?? null) !== ($locked['source']['reference'] ?? null)
    || ($actual['dist']['reference'] ?? null) !== ($locked['dist']['reference'] ?? null)
    || ($installed['dev'] ?? true) !== false || !empty($installed['dev-package-names'])) {
    throw new RuntimeException('Production dependencies do not match the packaging allowlist/lockfile.');
}
if (!is_dir($root.'/dist')) {
    mkdir($root.'/dist', 0770, true);
}
$target = $root.'/dist/neo-podcast-generator-'.ltrim($version, 'v').'.zip';
$entries = [];
$add = static function (string $from, string $to) use (&$entries, $read): void {
    $entries[$to] = $read($from);
};
foreach (['src' => 'php', 'templates' => 'php', 'migrations' => 'sql'] as $dir => $extension) {
    foreach (glob($root.'/'.$dir.'/*.'.$extension) as $file) {
        $add($dir.'/'.basename($file), '_neo/'.$dir.'/'.basename($file));
    }
}
foreach (['bootstrap.php', 'LICENSE', 'NOTICE'] as $file) {
    $add($file, '_neo/'.$file);
}
foreach (['app.css', 'app.js'] as $file) {
    $add('public/'.$file, $file);
}
// Composer's runtime files are explicit: never ship arbitrary files from vendor/composer.
foreach (['autoload_classmap.php', 'autoload_namespaces.php', 'autoload_psr4.php',
    'autoload_real.php', 'autoload_static.php', 'ClassLoader.php', 'installed.php',
    'InstalledVersions.php', 'platform_check.php', 'LICENSE'] as $file) {
    $add('vendor/composer/'.$file, '_neo/vendor/composer/'.$file);
}
// Only runtime PHP and license files from the pinned dependency.
foreach (['vendor/james-heinrich/getid3/getid3', 'vendor/james-heinrich/getid3/licenses'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && ($file->getExtension() === 'php' || str_contains($dir, '/licenses') || $file->getFilename() === 'LICENSE')) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $add($relative, '_neo/'.$relative);
        }
    }
}
$add('vendor/autoload.php', '_neo/vendor/autoload.php');
$add('vendor/james-heinrich/getid3/license.txt', '_neo/vendor/james-heinrich/getid3/license.txt');
$add('release/apache.htaccess', '.htaccess');
$add('release/nginx-root.conf', '_neo/nginx-root.conf');
$add('release/nginx-subfolder.conf', '_neo/nginx-subfolder.conf');
$add('docs/SHARED-HOSTING.md', 'INSTALL.md');
$entries['_neo/config.example.php'] = str_replace("'NEO_MODE' => 'server'", "'NEO_MODE' => 'shared'", $read('config.example.php'));
// Composer's root metadata otherwise embeds the build checkout's branch and commit.
$metadata = require $root.'/vendor/composer/installed.php';
$project = $metadata['root']['name'];
foreach (['pretty_version', 'version'] as $key) {
    $metadata['root'][$key] = $metadata['versions'][$project][$key] = ltrim($version, 'v');
}
$metadata['root']['reference'] = $metadata['versions'][$project]['reference'] = null;
$metadata['root']['install_path'] = $metadata['versions'][$project]['install_path'] = '__NEO_ROOT__';
$metadata['versions'][$locked['name']]['install_path'] = '__NEO_DEPENDENCY__';
$entries['_neo/vendor/composer/installed.php'] = "<?php return ".str_replace(
    ["'__NEO_ROOT__'", "'__NEO_DEPENDENCY__'"],
    ["__DIR__ . '/../..'", "__DIR__ . '/../james-heinrich/getid3'"],
    var_export($metadata, true)
).";\n";
$entries['_neo/defaults.php'] = "<?php\nreturn ['NEO_MODE' => 'shared'];\n";
$entries['_neo/.htaccess'] = "Require all denied\n";
$entries['_neo/VERSION'] = ltrim($version, 'v')."\n";
$entries['index.php'] = "<?php\ndeclare(strict_types=1);\n\$store = require __DIR__.'/_neo/bootstrap.php';\n(new Neo\\App(\$store))->run();\n";
// Composer may retain an older generated suffix when dependencies are refreshed.
foreach ($entries as $name => $content) {
    if ($name === '_neo/vendor/autoload.php' || str_starts_with($name, '_neo/vendor/composer/autoload_')) {
        $entries[$name] = preg_replace('/\b(ComposerAutoloaderInit|ComposerStaticInit)[a-f0-9]+\b/', '${1}NeoPodcastGenerator', $content);
    }
}
ksort($entries);
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create release ZIP.');
}
foreach ($entries as $name => $content) {
    if (!$zip->addFromString($name, $content)) {
        throw new RuntimeException('Cannot add '.$name);
    }
    if (!$zip->setCompressionName($name, ZipArchive::CM_STORE)
        || !$zip->setMtimeName($name, 946684800)
        || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
        throw new RuntimeException('Cannot normalize '.$name);
    }
}
if (!$zip->close()) {
    throw new RuntimeException('Cannot finish archive.');
}
$checksum = hash_file('sha256', $target).'  '.basename($target)."\n";
if (file_put_contents($target.'.sha256', $checksum) === false) {
    throw new RuntimeException('Cannot write checksum.');
}
echo $target."\n".$checksum;
