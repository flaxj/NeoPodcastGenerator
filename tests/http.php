<?php

declare(strict_types=1);
use Neo\{Store,Worker};

$httpRoot = $run.'/http-data';
$httpStore = new Store($httpRoot);
(new Worker($httpStore))->once();
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int)substr(strrchr($address, ':'), 1);
$base = 'http://127.0.0.1:'.$port;
$environment = getenv();
$environment['NEO_DATA'] = $httpRoot;
$environment['NEO_SECURE_COOKIES'] = '0';
$environment['NEO_SETUP_TOKEN'] = 'integration-test-setup-token-123456';
$command = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $command = [...$command,'-d','extension_dir='.realpath(ini_get('extension_dir')),'-d','extension=pdo_sqlite','-d','extension=fileinfo'];
}
$command = [...$command,'-S','127.0.0.1:'.$port,'-t',$root.'/public',$root.'/public/router.php'];
$server = proc_open($command, [0 => ['pipe','r'],1 => ['file',$run.'/http.log','a'],2 => ['file',$run.'/http.log','a']], $serverPipes, $root, $environment, ['bypass_shell' => true]);
if (!is_resource($server)) {
    throw new RuntimeException('Cannot start HTTP test server');
}
fclose($serverPipes[0]);
$cookie = '';
function request(string $method, string $path, string $body = '', array $headers = [], bool $authenticated = true): array
{
    global $base,$cookie;
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name.': '.$value;
    }
    if ($authenticated && $cookie) {
        $lines[] = 'Cookie: '.$cookie;
    }
    $ctx = stream_context_create(['http' => ['method' => $method,'content' => $body,'header' => implode("\r\n", $lines),'ignore_errors' => true,'follow_location' => 0,'timeout' => 15]]);
    $response = @file_get_contents($base.$path, false, $ctx);
    $received = $http_response_header ?? [];
    preg_match('~HTTP/\S+ (\d+)~', $received[0] ?? '', $match);
    $status = (int)($match[1] ?? 0);
    $mapped = [];
    foreach ($received as $header) {
        if (str_contains($header, ':')) {
            [$name,$value] = explode(':', $header, 2);
            $mapped[strtolower($name)] = trim($value);
        }
        if ($authenticated && preg_match('/^Set-Cookie: (neo_session=[^;]+)/i', $header, $m)) {
            $cookie = $m[1];
        }
    }
    return ['status' => $status,'body' => $response === false ? '' : $response,'headers' => $mapped];
}
function token(string $html): string
{
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}
function post(string $path, array $fields): array
{
    return request('POST', $path, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
}
try {
    $ready = false;
    for ($i = 0;$i < 50;$i++) {
        $probe = @fsockopen('127.0.0.1', $port);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        } usleep(100000);
    }
    check($ready, 'HTTP server starts');
    check(request('GET', '/')['status'] === 303, 'fresh installation redirects to setup');
    $setup = request('GET', '/setup');
    $csrf = token($setup['body']);
    check($setup['status'] === 200 && strlen($csrf) === 64, 'setup serves session-bound CSRF token');
    $fields = ['csrf' => $csrf,'setup_token' => $environment['NEO_SETUP_TOKEN'],'title' => 'HTTP Test Show','description' => 'Show description','base_url' => 'https://podcast.example.test','owner_name' => 'Test Owner','owner_email' => 'owner@example.test','language' => 'en','category' => 'Technology','username' => 'admin','password' => 'test-password-long-enough'];
    check(post('/setup', [...$fields,'csrf' => 'wrong'])['status'] === 403, 'setup rejects forged CSRF');
    check(post('/setup', [...$fields,'setup_token' => 'wrong'])['status'] === 422, 'setup requires server token');
    check(post('/setup', $fields)['status'] === 303, 'setup creates show and administrator');
    check($httpStore->settings()['title'] === 'HTTP Test Show', 'setup stores fields in correct columns');
    check(password_verify($fields['password'], $httpStore->one('SELECT password FROM admins')['password']), 'administrator password is hashed');
    check(request('GET', '/setup')['headers']['location'] === '/login', 'setup locks after completion');
    $dashboard = request('GET', '/admin');
    $csrf = token($dashboard['body']);
    check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Your episodes'), 'admin dashboard renders');
    Neo\Process::run([$ffmpeg,'-y','-v','error','-f','lavfi','-i','color=c=green:s=1400x1400','-frames:v','1',$run.'/cover.png']);
    $boundary = 'neo-test-'.bin2hex(random_bytes(12));
    $multipart = '';
    foreach ([...$fields,'csrf' => $csrf] as $name => $value) {
        $multipart .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
    }
    $multipart .= '--'.$boundary."\r\nContent-Disposition: form-data; name=\"artwork\"; filename=\"cover.png\"\r\nContent-Type: image/png\r\n\r\n".file_get_contents($run.'/cover.png')."\r\n--".$boundary."--\r\n";
    check(request('POST', '/admin/settings', $multipart, ['Content-Type' => 'multipart/form-data; boundary='.$boundary])['status'] === 303, 'show artwork uploads through settings');
    $art = $httpStore->settings()['artwork'];
    check($art !== null && request('GET', '/artwork/'.$art)['headers']['content-type'] === 'image/png', 'show artwork served with correct type');
    check(str_contains(request('GET', '/feeds/audio.xml')['body'], '/artwork/'.$art), 'show artwork included in RSS');
    check(request('GET', '/admin', '', [], false)['status'] === 303, 'anonymous admin access requires login');
    check(request('GET', '/api/uploads/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '', [], false)['status'] === 401, 'anonymous API read is denied');
    check(post('/admin/episodes/new', ['csrf' => 'wrong','title' => 'No'])['status'] === 403, 'episode mutations reject forged CSRF');
    $response = post('/admin/episodes/new', ['csrf' => $csrf,'title' => 'HTTP episode','description' => 'Safe <script>alert(1)</script> notes','episode_type' => 'full']);
    check($response['status'] === 303, 'create episode through HTTP');
    $editPath = $response['headers']['location'];
    $eid = basename($editPath);
    $editor = request('GET', $editPath);
    $csrf = token($editor['body']);
    check(!str_contains($editor['body'], '<script>alert'), 'episode text cannot inject scripts');
    check(request('GET', '/episodes/'.$eid)['status'] === 404, 'draft episode URL is private');
    check(post($editPath.'/publish', ['csrf' => $csrf])['status'] === 422, 'HTTP publication blocks missing media');
    $response = request('POST', '/api/uploads', json_encode(['episode_id' => $eid,'name' => 'video.mp4','size' => filesize($run.'/video.mp4')]), ['Content-Type' => 'application/json','X-CSRF-Token' => $csrf]);
    check($response['status'] === 200, 'authenticated upload creation');
    $uid = json_decode($response['body'], true)['id'];
    check(request('PUT', '/api/uploads/'.$uid, 'x', ['Upload-Offset' => '0'])['status'] === 403, 'chunk endpoint enforces CSRF');
    $response = request('PUT', '/api/uploads/'.$uid, file_get_contents($run.'/video.mp4'), ['Upload-Offset' => '0','X-CSRF-Token' => $csrf,'Content-Type' => 'application/octet-stream']);
    check($response['status'] === 200, 'HTTP binary chunk accepted');
    check(request('POST', '/api/uploads/'.$uid.'/finish', '', ['X-CSRF-Token' => $csrf])['status'] === 200, 'HTTP upload queued');
    (new Worker($httpStore))->once();
    $episode = $httpStore->one('SELECT * FROM episodes WHERE id=?', [$eid]);
    check($episode['audio_id'] !== null && $episode['video_id'] !== null, 'HTTP video upload processed by real worker');
    check(request('GET', '/media/'.$episode['audio_id'])['status'] === 404, 'draft audio URL cannot be accessed publicly');
    check(request('GET', '/admin/media/'.$episode['audio_id'], '', [], false)['status'] === 303, 'draft preview requires authentication');
    check(request('GET', '/admin/media/'.$episode['audio_id'])['status'] === 200, 'administrator can preview ready draft media');
    check(post($editPath.'/publish', ['csrf' => $csrf])['status'] === 303, 'HTTP publication succeeds after extraction');
    $public = request('GET', '/episodes/'.$eid);
    check($public['status'] === 200 && str_contains($public['body'], '<video') && str_contains($public['body'], '<audio'), 'public episode offers both players');
    check(isset($public['headers']['content-security-policy']), 'HTML responses apply content security policy');
    $feed = request('GET', '/feeds/audio.xml');
    check($feed['status'] === 200 && xml($feed['body'])->query('//enclosure')->length === 1, 'HTTP audio RSS is valid XML');
    check(request('GET', '/feeds/audio.xml', '', ['If-None-Match' => $feed['headers']['etag']])['status'] === 304, 'RSS conditional GET returns 304');
    check(request('HEAD', '/feeds/audio.xml')['body'] === '', 'RSS HEAD has no body');
    $media = request('GET', '/media/'.$episode['audio_id']);
    check($media['status'] === 200 && $media['headers']['content-type'] === 'audio/mpeg', 'public MP3 uses correct content type');
    $range = request('GET', '/media/'.$episode['audio_id'], '', ['Range' => 'bytes=0-9']);
    check($range['status'] === 206 && $range['body'] === substr($media['body'], 0, 10), 'media supports exact byte ranges');
    $suffix = request('GET', '/media/'.$episode['audio_id'], '', ['Range' => 'bytes=-10']);
    check($suffix['status'] === 206 && $suffix['body'] === substr($media['body'], -10), 'media supports suffix byte ranges');
    check(request('GET', '/media/'.$episode['audio_id'], '', ['Range' => 'bytes=999999999-'])['status'] === 416, 'invalid byte range returns 416');
    check(request('GET', '/media/'.$episode['audio_id'], '', ['Range' => 'bytes=0-9','If-Range' => '"stale"'])['status'] === 200, 'stale If-Range falls back to full media');
    $head = request('HEAD', '/media/'.$episode['audio_id']);
    check($head['body'] === '' && (int)$head['headers']['content-length'] === strlen($media['body']), 'media HEAD reports actual size');
    check(request('GET', '/media/'.$episode['audio_id'], '', ['If-None-Match' => $media['headers']['etag']])['status'] === 304, 'media conditional GET returns 304');
    check(request('GET', '/media/../../neo.sqlite')['status'] === 404, 'media path traversal is denied');
    check(request('GET', '/?q=unmatched')['status'] === 200 && str_contains(request('GET', '/?q=unmatched')['body'], 'No matching episodes'), 'public search handles no matches');
    check(request('GET', '/admin/distribution')['status'] === 200, 'distribution guidance renders');
    check(post($editPath.'/unpublish', ['csrf' => $csrf])['status'] === 303, 'unpublish through HTTP');
    check(request('GET', '/media/'.$episode['audio_id'])['status'] === 404 && request('GET', '/media/'.$episode['video_id'])['status'] === 404, 'unpublished media is inaccessible in both formats');
    check(post('/admin/logout', ['csrf' => $csrf])['status'] === 303, 'logout succeeds');
    check(request('GET', '/admin')['status'] === 303, 'logout revokes admin access');
    $login = request('GET', '/login');
    $csrf = token($login['body']);
    check(post('/login', ['csrf' => $csrf,'username' => 'admin','password' => 'wrong'])['status'] === 422, 'login rejects wrong password');
    check(post('/login', ['csrf' => $csrf,'username' => 'admin','password' => $fields['password']])['status'] === 303, 'login accepts correct credentials');
    check(request('GET', '/admin')['status'] === 200, 'authenticated session persists after login');
    $httpStore->run('UPDATE admins SET password=?', [password_hash('a-new-test-password', PASSWORD_DEFAULT)]);
    check(request('GET', '/admin')['status'] === 303, 'password reset invalidates existing sessions');
} finally {
    proc_terminate($server);
    proc_close($server);
}
