<?php

declare(strict_types=1);

[$script, $archive, $documentRoot, $prefix] = $argv;
$destination = $documentRoot.$prefix;
if (!is_dir($destination)) {
    mkdir($destination, 0770, true);
}
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open release archive.');
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === '_neo/config.php' || preg_match('~(^|/)(?:\.git|\.env|\.tools|tests|docker|var|helperapps|demos)(/|$)|\.exe$|^Dockerfile|^compose~i', $name)) {
        throw new RuntimeException('Forbidden release entry: '.$name);
    }
}
foreach (['index.php', 'app.css', 'app.js', '.htaccess', '_neo/vendor/autoload.php', '_neo/migrations/001.sql', '_neo/LICENSE', '_neo/config.example.php'] as $required) {
    if ($zip->locateName($required) === false) {
        throw new RuntimeException('Missing release entry: '.$required);
    }
}
if (!$zip->extractTo($destination)) {
    throw new RuntimeException('Cannot extract release.');
}
$zip->close();
// A real executable probe verifies server policy, rather than just a missing URL.
file_put_contents($destination.'/evil.php', '<?php echo "Unexpected PHP execution";');
$config = $destination.'/_neo/config.php';
if (!is_file($config)) {
    file_put_contents($config, '<?php return '.var_export([
        'NEO_SETUP_TOKEN' => 'package-test-token-not-for-production',
        'NEO_BASE_PATH' => $prefix,
        'NEO_SECURE_COOKIES' => '0',
    ], true).';');
}
echo "Package allowlist and extraction passed: $destination\n";
