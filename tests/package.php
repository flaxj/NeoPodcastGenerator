<?php

declare(strict_types=1);

// Exercise the builder in an isolated source copy, leaving local releases intact.
$root = dirname(__DIR__);
date_default_timezone_set('UTC');
$run = $root.'/test-results/package-build-'.bin2hex(random_bytes(6));
mkdir($run, 0770, true);
$files = ['bin/package.php', 'bootstrap.php', 'composer.lock', 'config.example.php', 'LICENSE', 'NOTICE'];
foreach (['src', 'templates', 'migrations', 'public', 'vendor', 'release', 'docs'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
foreach ($files as $file) {
    if (!is_dir(dirname($run.'/'.$file))) {
        mkdir(dirname($run.'/'.$file), 0770, true);
    }
    copy($root.'/'.$file, $run.'/'.$file);
}
$php = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $php = [...$php, '-n', '-d', 'extension_dir='.realpath(ini_get('extension_dir')), '-d', 'extension=zip'];
}
$build = static function (string $version = '1.0.0', bool $success = true) use ($php, $run): void {
    $process = proc_open([...$php, $run.'/bin/package.php', $version], [0 => ['pipe', 'r'], 1 => ['file', $run.'/build.log', 'a'], 2 => ['file', $run.'/build.log', 'a']], $pipes, $run);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch package builder');
    }
    fclose($pipes[0]);
    if ((proc_close($process) === 0) !== $success) {
        throw new RuntimeException('Unexpected build result: '.file_get_contents($run.'/build.log'));
    }
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};
$build();
$archive = $run.'/dist/neo-podcast-generator-1.0.0.zip';
$digest = hash_file('sha256', $archive);
$check(file_get_contents($archive.'.sha256') === $digest.'  '.basename($archive)."\n", 'checksum matches archive');
$build('v1.0.0');
$check(hash_file('sha256', $archive) === $digest, 'repeat builds and optional v prefix are byte-identical');
$zip = new ZipArchive();
$check($zip->open($archive) === true, 'release ZIP opens');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === '_neo/config.php' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')
        || preg_match('~(^|/)(?:\.env|\.git|var|tests|docker|helperapps|demos)(/|$)|\.exe$~i', $name)) {
        throw new RuntimeException('Forbidden archive entry: '.$name);
    }
    $zip->getExternalAttributesIndex($i, $system, $attributes);
    if ($system !== ZipArchive::OPSYS_UNIX || ($attributes >> 16) !== 0100644) {
        throw new RuntimeException('Unexpected permissions: '.$name);
    }
    if ($zip->statIndex($i)['mtime'] !== 946684800) {
        throw new RuntimeException('Unexpected timestamp: '.$name);
    }
    $names[] = $name;
}
$sorted = $names;
sort($sorted);
$check($names === $sorted && count(array_unique($names)) === count($names), 'archive entries are safe, unique, sorted, and have normalized permissions');
foreach (['index.php', '.htaccess', 'app.css', 'app.js', 'INSTALL.md', '_neo/.htaccess', '_neo/LICENSE', '_neo/NOTICE', '_neo/config.example.php', '_neo/nginx-root.conf', '_neo/nginx-subfolder.conf'] as $required) {
    $check($zip->locateName($required) !== false, 'release includes '.$required);
}
$check($zip->getFromName('_neo/VERSION') === "1.0.0\n", 'version is embedded');
foreach (['src' => 'php', 'templates' => 'php', 'migrations' => 'sql'] as $dir => $extension) {
    foreach (glob($root.'/'.$dir.'/*.'.$extension) as $file) {
        $check($zip->locateName('_neo/'.$dir.'/'.basename($file)) !== false, 'runtime file included: '.$dir.'/'.basename($file));
    }
}
$destination = $run.'/relocated/podcast';
mkdir($destination, 0770, true);
$check($zip->extractTo($destination), 'archive extracts into a relocated subfolder');
$zip->close();
$loader = require $destination.'/_neo/vendor/autoload.php';
foreach ($loader->getClassMap() as $class => $file) {
    if (!is_file($file) || !str_starts_with(str_replace('\\', '/', realpath($file)), str_replace('\\', '/', realpath($destination)).'/')) {
        throw new RuntimeException('Missing or nonportable autoload entry: '.$class);
    }
}
$check(class_exists(Neo\App::class) && class_exists(getID3::class), 'relocated application and dependency autoload successfully');
$check((require $destination.'/_neo/defaults.php')['NEO_MODE'] === 'shared', 'release defaults to shared mode');
$source = $run.'/public/app.js';
file_put_contents($source, str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents($source))));
$build();
$check(hash_file('sha256', $archive) === $digest, 'Windows line endings do not change release bytes');
foreach (['vendor/autoload.php', ...array_map(fn ($file) => 'vendor/composer/'.basename($file), glob($run.'/vendor/composer/autoload_*.php'))] as $file) {
    file_put_contents($run.'/'.$file, preg_replace('/\b(ComposerAutoloaderInit|ComposerStaticInit)[a-f0-9]+\b/', '${1}abcdef123456', file_get_contents($run.'/'.$file)));
}
$build();
$check(hash_file('sha256', $archive) === $digest, 'Composer autoloader suffix does not change release bytes');
$metadataPath = $run.'/vendor/composer/installed.json';
$original = file_get_contents($metadataPath);
$metadata = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
foreach (['extra', 'name', 'version', 'reference', 'dev'] as $case) {
    $bad = $metadata;
    switch ($case) {
        case 'extra': $bad['packages'][] = $bad['packages'][0]; break;
        case 'name': $bad['packages'][0]['name'] = 'unexpected/package'; break;
        case 'version': $bad['packages'][0]['version'] = 'v0.0.0'; break;
        case 'reference': $bad['packages'][0]['source']['reference'] = 'wrong'; break;
        case 'dev': $bad['dev'] = true; break;
    }
    file_put_contents($metadataPath, json_encode($bad, JSON_THROW_ON_ERROR));
    $build(success: false);
    $check(hash_file('sha256', $archive) === $digest, 'rejects '.$case.' dependency mismatch without overwriting release');
}
file_put_contents($metadataPath, $original);
$build('../invalid', false);
rename($run.'/public/app.js', $run.'/public/app.js.saved');
$build(success: false);
rename($run.'/public/app.js.saved', $run.'/public/app.js');
if (PHP_OS_FAMILY !== 'Windows') {
    rename($run.'/templates', $run.'/saved-templates');
    symlink($run.'/saved-templates', $run.'/templates');
    $build(success: false);
    $check(true, 'rejects symlinked input directories');
}
$check(hash_file('sha256', $archive) === $digest, 'invalid version and missing inputs preserve existing release');
echo 'Package checks passed. Artifacts: '.$run."\n";
