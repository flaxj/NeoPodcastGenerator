<?php

declare(strict_types=1);

// Real-media integration tests; no external services or framework required.
$root = dirname(__DIR__);
$run = $root.'/test-results/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run, 0770, true);
putenv('NEO_DATA='.$run.'/data');
$store = require $root.'/bootstrap.php';
use Neo\{Store,Process,Media,Uploads,Publishing,Worker,Feed};

$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    } ++$count;
    echo "PASS: $message\n";
}
function rejects(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable) {
        check(true, $message);
        return;
    } check(false, $message);
}
function xml(string $body): DOMXPath
{
    $doc = new DOMDocument();
    if (!$doc->loadXML($body)) {
        throw new RuntimeException('Malformed RSS');
    } return new DOMXPath($doc);
}
function items(Store $s, string $kind): DOMXPath
{
    return xml((new Feed($s))->render($kind));
}
function upload(Store $s, string $episode, string $path, string $ext): string
{
    $service = new Uploads($s);
    $bytes = file_get_contents($path);
    $u = $service->create($episode, 'fixture.'.$ext, strlen($bytes));
    $middle = max(1, (int)(strlen($bytes) / 2));
    $service->append($u['id'], 0, substr($bytes, 0, $middle));
    if ($middle < strlen($bytes)) {
        $service->append($u['id'], $middle, substr($bytes, $middle));
    }
    $service->finish($u['id']);
    return $u['id'];
}
$ffmpeg = getenv('FFMPEG_BIN') ?: 'ffmpeg';
try {
    Process::run([$ffmpeg,'-y','-v','error','-f','lavfi','-i','sine=frequency=440:duration=1','-c:a','libmp3lame',$run.'/audio.mp3']);
    foreach (['mp4','mov'] as $ext) {
        Process::run([$ffmpeg,'-y','-v','error','-f','lavfi','-i','color=c=green:s=160x120:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac','-shortest',$run.'/video.'.$ext]);
    }
    Process::run([$ffmpeg,'-y','-v','error','-f','lavfi','-i','color=c=green:s=160x120:d=1','-c:v','libx264','-an',$run.'/silent.mp4']);
    Process::run([$ffmpeg,'-y','-v','error','-f','lavfi','-i','color=c=green:s=160x120:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-f','lavfi','-i','sine=frequency=880:duration=1','-map','0:v','-map','1:a','-map','2:a','-c:v','libx264','-c:a','aac','-disposition:a:0','0','-disposition:a:1','default',$run.'/multi.mp4']);
    check((new Media())->probe($run.'/multi.mp4', 'mp4')['track'] === 2, 'default audio track takes precedence over first track');
    check((new Media())->probe($run.'/video.mp4', 'mp4')['track'] === 1, 'single audio track is selected');
    if (PHP_OS_FAMILY !== 'Windows') {
        check((fileperms($store->root.'/assets') & 0005) === 0005, 'finalized media directory is readable/traversable by Nginx');
        check((fileperms($store->root.'/uploads') & 0077) === 0, 'incomplete uploads directory stays private');
        check((fileperms($store->root.'/neo.sqlite') & 0077) === 0, 'SQLite database stays private');
    }
    $store->run('INSERT INTO settings(id,title,description,base_url,owner_name,owner_email,language,category,explicit,updated_at) VALUES(1,?,?,?,?,?,?,?,?,?)', ['A & B <show>','A <description> & more','https://podcast.example.test','Author','owner@example.test','en','Technology',0,time()]);
    $pub = new Publishing($store);
    $worker = new Worker($store);
    $uploads = new Uploads($store);
    $e = $pub->save(null, ['title' => 'First & <episode>','description' => 'Notes & <b>plain</b>','number' => '1']);
    check(items($store, 'audio')->query('//item')->length === 0, 'draft without media excluded');
    rejects(fn () => $pub->publish($e), 'cannot publish without ready audio');
    rejects(fn () => $pub->save(null, ['title' => "Bad\x01title"]), 'reject XML control characters in episode metadata');
    rejects(fn () => $pub->save(null, ['title' => "Bad\xffencoding"]), 'reject invalid UTF-8 metadata');
    rejects(fn () => $uploads->create($e, 'script.php', 10), 'reject executable extension');
    rejects(fn () => $uploads->create($e, 'large.mp4', 10737418241), 'enforce file size limit');
    rejects(fn () => $uploads->create('../../missing', 'test.mp3', 10), 'reject unknown episode and path abuse');
    $raw = file_get_contents($run.'/audio.mp3');
    $u = $uploads->create($e, 'semi;$(whoami).mp3', strlen($raw));
    $uploads->append($u['id'], 0, substr($raw, 0, 20));
    rejects(fn () => $uploads->finish($u['id']), 'cannot finish incomplete upload');
    rejects(fn () => $uploads->append($u['id'], 0, 'bad'), 'reject stale chunk offset');
    $reopened = new Uploads(new Store($store->root));
    check((int)$reopened->get($u['id'])['offset'] === 20, 'upload offset survives restart');
    $reopened->append($u['id'], 20, substr($raw, 20));
    $reopened->finish($u['id']);
    $reopened->finish($u['id']);
    check((int)$store->one('SELECT COUNT(*) n FROM jobs')['n'] === 1, 'upload finalization is idempotent');
    check($worker->once(), 'worker processes queued MP3');
    $job = $store->one('SELECT * FROM jobs WHERE upload_id=?', [$u['id']]);
    check($job['state'] === 'ready', 'MP3 validation succeeds');
    $uploads->finish($u['id']);
    check((int)$store->one('SELECT COUNT(*) n FROM jobs')['n'] === 1, 'completed finalization stays idempotent');
    $pub->publish($e);
    $audio = items($store, 'audio');
    check($audio->query('//item')->length === 1 && items($store, 'video')->query('//item')->length === 0, 'audio-only appears exclusively in audio feed');
    check($audio->evaluate('string(//item/title)') === 'First & <episode>', 'RSS XML escapes special characters');
    check($audio->evaluate('string(//item/description)') === 'Notes & plain', 'show notes sanitized to plain text');
    $guid = $audio->evaluate('string(//item/guid)');
    check($audio->evaluate('string(//enclosure/@type)') === 'audio/mpeg', 'MP3 enclosure MIME is correct');
    check((int)$audio->evaluate('string(//enclosure/@length)') === strlen($raw), 'enclosure length matches actual bytes');
    check($audio->query('//item/enclosure')->length === 1, 'exactly one enclosure per episode');
    foreach (['mp4','mov'] as $ext) {
        $id = $pub->save(null, ['title' => 'Video '.$ext,'description' => 'Video notes']);
        $uid = upload($store, $id, $run.'/video.'.$ext, $ext);
        rejects(fn () => $pub->publish($id), 'video cannot publish while queued: '.$ext);
        $store->run("UPDATE jobs SET state='processing' WHERE upload_id=?", [$uid]);
        $worker->once();
        $state = $store->one('SELECT * FROM jobs WHERE upload_id=?', [$uid]);
        check($state['state'] === 'ready', 'abandoned job recovers and converts '.$ext.': '.($state['error'] ?? ''));
        $row = $store->one('SELECT * FROM episodes WHERE id=?', [$id]);
        $a = $store->one('SELECT * FROM assets WHERE id=?', [$row['audio_id']]);
        $v = $store->one('SELECT * FROM assets WHERE id=?', [$row['video_id']]);
        check($a && $v, 'both assets attach together for '.$ext);
        check(hash_file('sha256', $run.'/video.'.$ext) === hash_file('sha256', $store->root.'/assets/'.$v['filename']), 'original video preserved: '.$ext);
        $probe = json_decode(Process::run([getenv('FFPROBE_BIN') ?: 'ffprobe','-v','error','-show_streams','-of','json',$store->root.'/assets/'.$a['filename']]), true);
        check($probe['streams'][0]['codec_name'] === 'mp3' && (int)$probe['streams'][0]['sample_rate'] === 44100 && $probe['streams'][0]['channels'] === 2, 'extracted MP3 has required codec, sample rate, channels');
        check((int)$probe['streams'][0]['bit_rate'] === 192000, 'extracted MP3 is 192 kbps');
        $pub->publish($id);
        $ax = items($store, 'audio');
        $vx = items($store, 'video');
        check($ax->query('//item[guid="urn:uuid:'.$id.'"]')->length === 1 && $vx->query('//item[guid="urn:uuid:'.$id.'"]')->length === 1, 'paired feeds share episode GUID: '.$ext);
        check($vx->evaluate('string(//item[guid="urn:uuid:'.$id.'"]/enclosure/@type)') === ($ext === 'mov' ? 'video/quicktime' : 'video/mp4'), 'video MIME correct: '.$ext);
    }
    $old = $store->one('SELECT * FROM episodes WHERE id=?', [$e]);
    $before = (new Feed($store))->render('audio');
    $replacement = upload($store, $e, $run.'/video.mp4', 'mp4');
    check((new Feed($store))->render('audio') === $before, 'published media unchanged during replacement queue');
    $worker->once();
    $changed = $store->one('SELECT * FROM episodes WHERE id=?', [$e]);
    check($changed['audio_id'] !== $old['audio_id'] && $changed['video_id'] !== null, 'replacement atomically switches the pair');
    check(items($store, 'audio')->evaluate('string(//item[title="First & <episode>"]/guid)') === $guid, 'GUID survives media replacement');
    $bad = upload($store, $e, $run.'/silent.mp4', 'mp4');
    $worker->once();
    check($store->one('SELECT state FROM jobs WHERE upload_id=?', [$bad])['state'] === 'failed', 'silent video fails conversion');
    check($store->one('SELECT audio_id FROM episodes WHERE id=?', [$e])['audio_id'] === $changed['audio_id'], 'failed replacement preserves published audio');
    file_put_contents($run.'/corrupt.mp3', 'not an mp3');
    $broken = $pub->save(null, ['title' => 'Bad media','description' => 'Bad']);
    upload($store, $broken, $run.'/corrupt.mp3', 'mp3');
    $worker->once();
    check($store->one('SELECT state FROM jobs WHERE episode_id=?', [$broken])['state'] === 'failed', 'corrupt MP3 rejected');
    rejects(fn () => $pub->publish($broken), 'failed episodes cannot publish');
    $store->run("UPDATE jobs SET state='queued' WHERE upload_id=?", [$bad]);
    copy($run.'/video.mp4', $store->root.'/uploads/'.$bad.'.part');
    $worker->once();
    check($store->one('SELECT state FROM jobs WHERE upload_id=?', [$bad])['state'] === 'ready', 'failed job can be retried');
    $pub->save($e, ['title' => 'Edited title','description' => 'Updated notes']);
    check(items($store, 'audio')->query('//item[guid="'.$guid.'"]')->length === 1, 'metadata edit preserves GUID');
    $lock = fopen($store->root.'/worker.lock', 'c');
    flock($lock, LOCK_EX);
    check(!$worker->once(), 'second worker cannot enter active worker lock');
    flock($lock, LOCK_UN);
    fclose($lock);
    $store->run("UPDATE episodes SET state='draft' WHERE id=?", [$e]);
    check(items($store, 'audio')->query('//item[guid="'.$guid.'"]')->length === 0 && items($store, 'video')->query('//item[guid="'.$guid.'"]')->length === 0, 'unpublishing removes both versions');
    $pub->delete($broken);
    check(!$store->one('SELECT id FROM jobs WHERE episode_id=?', [$broken]), 'deleting episode cascades jobs');
    $again = new Store($store->root);
    check(count($again->all('SELECT * FROM episodes')) === 3, 'data survives reopening database');
    $backup = $run.'/backup.sqlite';
    $store->run('VACUUM INTO ?', [$backup]);
    $copy = new PDO('sqlite:'.$backup);
    check((int)$copy->query('SELECT COUNT(*) FROM episodes')->fetchColumn() === 3, 'SQLite backup preserves episode records');
    (new Neo\Maintenance($store))->backup($run.'/full-backup');
    $restored = new Store($run.'/full-backup');
    check(is_file($run.'/full-backup/BACKUP-COMPLETE'), 'full backup has completion marker');
    check((new Feed($restored))->render('audio') === (new Feed($store))->render('audio'), 'restored backup generates identical RSS');
    foreach ($restored->all('SELECT * FROM assets') as $asset) {
        check(is_file($restored->root.'/assets/'.$asset['filename']), 'backup includes media '.$asset['kind']);
    }
    $garbage = (new Neo\Maintenance($restored))->garbageCollect();
    check(count($garbage) > 0, 'garbage collection identifies replaced media');
    (new Neo\Maintenance($restored))->garbageCollect(true);
    check((new Feed($restored))->render('audio') === (new Feed($store))->render('audio'), 'garbage collection preserves published feed');
    file_put_contents($run.'/feed-audio.xml', (new Feed($store))->render('audio'));
    file_put_contents($run.'/feed-video.xml', (new Feed($store))->render('video'));
    require __DIR__.'/http.php';
    echo "\n$count assertions passed. Artifacts: $run\n";
} catch (Throwable $e) {
    fwrite(STDERR, (string)$e."\nArtifacts: $run\n");
    exit(1);
}
