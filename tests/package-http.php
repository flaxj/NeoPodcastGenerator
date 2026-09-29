<?php

declare(strict_types=1);

[$script, $origin, $prefix, $fixtures] = $argv;
$cookie = '';
function ok(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS: $label\n";
}
function req(string $method, string $path, string $body = '', array $headers = [], bool $auth = true): array
{
    global $origin, $prefix, $cookie;
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "$k: $v";
    }
    if ($auth && $cookie) {
        $lines[] = 'Cookie: '.$cookie;
    }
    $context = stream_context_create(['http' => ['method' => $method, 'content' => $body, 'header' => implode("\r\n", $lines), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
    $response = @file_get_contents($origin.$prefix.$path, false, $context);
    $received = $http_response_header ?? [];
    preg_match('~HTTP/\S+ (\d+)~', $received[0] ?? '', $m);
    $mapped = [];
    foreach ($received as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $mapped[strtolower($k)] = trim($v);
        }
        if ($auth && preg_match('/^Set-Cookie: (neo_session(?:_[a-f0-9]+)?=[^;]+)/i', $line, $match)) {
            $cookie = $match[1];
        }
    }
    return ['status' => (int)($m[1] ?? 0), 'body' => $response ?: '', 'headers' => $mapped];
}
function csrf(array $response): string
{
    preg_match('/name="csrf-token" content="([^"]+)"/', $response['body'], $m);
    return $m[1] ?? '';
}
function post(string $path, array $fields): array
{
    return req('POST', $path, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
}
if (($argv[4] ?? '') === '--upgrade') {
    $login = req('GET', '/login');
    ok(post('/login', ['csrf' => csrf($login), 'username' => 'admin', 'password' => 'package-test-password'])['status'] === 303, 'upgrade preserves credentials and local config');
    $feed = req('GET', '/feeds/audio.xml');
    ok(str_contains($feed['body'], '<enclosure'), 'upgrade preserves published episode');
    preg_match('~<enclosure[^>]+url="[^"]+(/media/[a-f0-9-]+)"~', $feed['body'], $m);
    ok(isset($m[1]) && req('GET', $m[1])['status'] === 200, 'upgrade preserves playable media');
    foreach (['/_neo/config.php', '/_neo/var/neo.sqlite', '/_neo/vendor/', '/.htaccess', '/evil.php'] as $private) {
        ok(in_array(req('GET', $private, '', [], false)['status'], [403, 404], true), 'upgrade still blocks '.$private);
    }
    exit;
}
$setup = req('GET', '/setup');
ok($setup['status'] === 200, 'setup renders without worker or FFmpeg');
ok(str_contains($setup['headers']['set-cookie'] ?? '', 'path='.$prefix.'/'), 'cookie scoped to installation');
ok(str_contains($setup['body'], 'href="'.$prefix.'/app.css"'), 'assets use installation prefix');
ok(req('GET', '/app.css')['status'] === 200 && req('GET', '/app.js')['status'] === 200, 'static assets load');
$fields = ['csrf' => csrf($setup), 'setup_token' => 'package-test-token-not-for-production', 'title' => 'Package Show', 'description' => 'Shared host', 'base_url' => 'https://example.test'.$prefix, 'owner_name' => 'Owner', 'owner_email' => 'owner@example.test', 'language' => 'en', 'category' => 'Technology', 'username' => 'admin', 'password' => 'package-test-password'];
ok(post('/setup', [...$fields, 'setup_token' => 'bad'])['status'] === 422, 'setup rejects invalid token');
ok(post('/setup', $fields)['status'] === 303, 'browser setup succeeds without processes');
ok(req('GET', '/setup')['headers']['location'] === $prefix.'/login', 'setup locks and redirect includes prefix');
$token = csrf(req('GET', '/admin'));
$boundary = 'neo-package-boundary';
$body = '';
foreach ([...$fields, 'csrf' => $token] as $k => $v) {
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
}
$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"artwork\"; filename=\"cover.png\"\r\nContent-Type: image/png\r\n\r\n".file_get_contents($fixtures.'/cover.png')."\r\n--$boundary--\r\n";
ok(req('POST', '/admin/settings', $body, ['Content-Type' => 'multipart/form-data; boundary='.$boundary])['status'] === 303, 'artwork upload works');
$created = post('/admin/episodes/new', ['csrf' => $token, 'title' => 'MP3 episode', 'description' => 'Package test', 'episode_type' => 'full']);
ok($created['status'] === 303, 'draft creation works');
$id = basename($created['headers']['location']);
$headers = ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token];
ok(req('POST', '/api/uploads', json_encode(['episode_id' => $id, 'name' => 'video.mp4', 'size' => 100]), $headers)['status'] === 422, 'video rejected in shared mode');
$bytes = file_get_contents($fixtures.'/audio.mp3');
$u = req('POST', '/api/uploads', json_encode(['episode_id' => $id, 'name' => 'audio.mp3', 'size' => strlen($bytes)]), $headers);
ok($u['status'] === 200, 'MP3 upload creation works');
$uid = json_decode($u['body'], true)['id'];
$half = intdiv(strlen($bytes), 2);
ok(req('PUT', '/api/uploads/'.$uid, substr($bytes, 0, $half), ['Upload-Offset' => '0', 'X-CSRF-Token' => $token])['status'] === 200, 'first upload chunk accepted');
ok(json_decode(req('GET', '/api/uploads/'.$uid)['body'], true)['offset'] === $half, 'interrupted upload exposes resume offset');
ok(req('PUT', '/api/uploads/'.$uid, substr($bytes, $half), ['Upload-Offset' => (string)$half, 'X-CSRF-Token' => $token])['status'] === 200, 'resumed chunk accepted');
$finish = req('POST', '/api/uploads/'.$uid.'/finish', '', ['X-CSRF-Token' => $token]);
ok((json_decode($finish['body'], true)['state'] ?? '') === 'ready', 'MP3 ready in HTTP request: '.$finish['body']);
ok(req('POST', '/api/uploads/'.$uid.'/finish', '', ['X-CSRF-Token' => $token])['body'] === $finish['body'], 'repeat finish does not duplicate media');
$editor = req('GET', '/admin/episodes/'.$id);
preg_match('~/admin/media/([a-f0-9-]+)~', $editor['body'], $m);
ok(isset($m[1]), 'draft preview link renders');
$asset = $m[1];
ok(req('GET', '/media/'.$asset, '', [], false)['status'] === 404, 'draft media is private');
ok(req('GET', '/admin/media/'.$asset, '', [], false)['status'] === 303, 'draft preview requires login');
ok(req('GET', '/admin/media/'.$asset)['body'] === $bytes, 'authenticated preview works');
ok(post('/admin/episodes/'.$id.'/publish', ['csrf' => $token])['status'] === 303, 'publication succeeds');
$feed = req('GET', '/feeds/audio.xml');
$xml = new DOMDocument();
ok($xml->loadXML($feed['body']), 'feed is valid XML');
ok($xml->getElementsByTagName('enclosure')->item(0)->getAttribute('url') === 'https://example.test'.$prefix.'/media/'.$asset, 'enclosure URL includes installation path');
ok(req('GET', '/media/'.$asset, '', [], false)['body'] === $bytes, 'public download matches upload');
$range = req('GET', '/media/'.$asset, '', ['Range' => 'bytes=0-9'], false);
ok($range['status'] === 206 && $range['body'] === substr($bytes, 0, 10), 'byte ranges work');
$head = req('HEAD', '/media/'.$asset, '', [], false);
ok($head['body'] === '' && (int)$head['headers']['content-length'] === strlen($bytes), 'HEAD reports media length');
foreach (['/_neo', '/_neo/config.php', '/_neo/var/neo.sqlite', '/_neo/var/neo.sqlite-wal', '/_neo/var/uploads/', '/_neo/vendor/', '/_neo/src/App.php', '/.htaccess', '/evil.php', '/index.php/extra'] as $private) {
    ok(in_array(req('GET', $private, '', [], false)['status'], [403, 404], true), 'blocked '.$private);
}
ok(post('/admin/logout', ['csrf' => $token])['status'] === 303, 'logout works');
$login = req('GET', '/login');
ok(post('/login', ['csrf' => csrf($login), 'username' => 'admin', 'password' => 'package-test-password'])['status'] === 303, 'login works');
