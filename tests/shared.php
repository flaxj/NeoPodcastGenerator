<?php

declare(strict_types=1);

// Run with process execution disabled against an extracted release.
[$script, $installation, $fixtures] = $argv;
putenv('NEO_DATA='.$installation.'/_neo/unit-data');
$store = require $installation.'/_neo/bootstrap.php';
function verify(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS: $label\n";
}
function reject(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (Throwable) {
        verify(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
verify(Neo\Config::shared(), 'archive defaults to shared mode without configuration override');
verify(!function_exists('proc_open'), 'PHP process execution is disabled');
verify(Neo\Config::chunkSize() === 1048576, 'shared transport uses one MiB');
$publisher = new Neo\Publishing($store);
$uploads = new Neo\Uploads($store);
$episode = $publisher->save(null, ['title' => 'Shared', 'description' => 'Description', 'episode_type' => 'full']);
reject(fn () => $uploads->create($episode, 'video.mp4', 100), 'shared mode rejects video');
$submit = function (string $bytes) use ($uploads, $episode): array {
    $u = $uploads->create($episode, 'audio.mp3', strlen($bytes));
    $half = max(1, intdiv(strlen($bytes), 2));
    $uploads->append($u['id'], 0, substr($bytes, 0, $half));
    reject(fn () => $uploads->finish($u['id']), 'incomplete finish rejected');
    reject(fn () => $uploads->append($u['id'], 0, 'x'), 'stale upload offset rejected');
    $uploads->append($u['id'], $half, substr($bytes, $half));
    return [$u['id'], $uploads->finish($u['id'])];
};
foreach (['audio.mp3', 'vbr.mp3'] as $fixture) {
    [$id, $result] = $submit(file_get_contents($fixtures.'/'.$fixture));
    verify($result['state'] === 'ready' && !$result['queued'], 'pure PHP parses '.$fixture.': '.($result['error'] ?? ''));
    verify($uploads->finish($id) === $result, 'repeated finish is idempotent');
    verify($store->one('SELECT duration FROM assets ORDER BY rowid DESC LIMIT 1')['duration'] > 0, 'duration is positive');
}
$store->run("UPDATE episodes SET state='published',published_at=? WHERE id=?", [time(), $episode]);
$before = $store->one('SELECT audio_id FROM episodes WHERE id=?', [$episode])['audio_id'];
[$bad, $result] = $submit('not an mp3 file');
verify($result['state'] === 'failed', 'malformed MP3 fails');
verify($store->one('SELECT audio_id FROM episodes WHERE id=?', [$episode])['audio_id'] === $before, 'failed replacement preserves published audio');
$store->run("UPDATE jobs SET state='queued' WHERE upload_id=?", [$bad]);
verify($uploads->finish($bad)['state'] === 'failed', 'retry safely repeats failed inspection');
$lock = fopen($store->root.'/worker.lock', 'c');
flock($lock, LOCK_EX);
$bytes = file_get_contents($fixtures.'/audio.mp3');
$u = $uploads->create($episode, 'audio.mp3', strlen($bytes));
$uploads->append($u['id'], 0, $bytes);
verify($uploads->finish($u['id'])['queued'], 'busy processor leaves upload queued');
flock($lock, LOCK_UN);
fclose($lock);
verify($uploads->finish($u['id'])['state'] === 'ready', 'queued upload recovers on repeated finish');
verify($store->one('SELECT audio_id FROM episodes WHERE id=?', [$episode])['audio_id'] !== $before, 'successful replacement attaches new media');
putenv('NEO_MODE=server');
verify(!Neo\Config::shared(), 'environment overrides package defaults');
putenv('NEO_MODE');
putenv('NEO_BASE_PATH=/other');
verify(Neo\Config::url('/login') === '/other/login', 'environment overrides local path');
putenv('NEO_BASE_PATH=/../escape');
reject(fn () => Neo\Config::basePath(), 'unsafe installation path rejected');
putenv('NEO_BASE_PATH');
