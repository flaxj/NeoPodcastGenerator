<?php

declare(strict_types=1);

namespace Neo;

final class App
{
    private string $path;
    private array $settings;
    public function __construct(private Store $store)
    {
        $this->settings = $store->settings();
        $this->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $prefix = Config::basePath();
        if ($prefix !== '') {
            $this->path = str_starts_with($this->path, $prefix.'/') ? substr($this->path, strlen($prefix)) : ($this->path === $prefix ? '/' : '/__outside_installation');
        }
    }
    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        try {
            $this->route();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(422);
            if (str_starts_with($this->path, '/api/')) {
                $this->json(['error' => $e->getMessage()]);
            } else {
                $this->view('message', ['title' => 'Please check your input','message' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            error_log((string)$e);
            http_response_code(500);
            if (str_starts_with($this->path, '/api/')) {
                $this->json(['error' => 'Unexpected server error. Check the application logs.']);
            } else {
                $this->view('message', ['title' => 'Something went wrong','message' => 'Check the application logs and storage permissions, then try again.']);
            }
        }
    }
    private function session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // A subfolder must not consume a parent site's PHP session cookie.
            $suffix = Config::basePath() === '' ? '' : '_'.substr(hash('sha256', Config::basePath()), 0, 12);
            session_name('neo_session'.$suffix);
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params(['secure' => \Neo\Config::get('NEO_SECURE_COOKIES') !== '0','httponly' => true,'samesite' => 'Lax','path' => Config::url('/')]);
            session_start();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        header('Cache-Control: private, no-store');
    }
    private function csrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(403);
            $this->json(['error' => 'Session expired or invalid CSRF token. Reload the page.']);
            exit;
        }
    }
    private function redirect(string $path): never
    {
        header('Location: '.Config::url($path), true, 303);
        exit;
    }
    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }
    private function input(): array
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid JSON request.');
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        } return $data;
    }
    private function view(string $view, array $data = []): void
    {
        $settings = $this->settings;
        $csrf = $_SESSION['csrf'] ?? '';
        $admin = !empty($_SESSION['admin']);
        $escape = static fn (mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        require __DIR__.'/../templates/'.$view.'.php';
        $content = ob_get_clean();
        require __DIR__.'/../templates/layout.php';
    }
    private function route(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET','HEAD','POST','PUT'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD, POST, PUT');
            return;
        }
        if (!$this->settings && $this->path !== '/setup') {
            $this->redirect('/setup');
        }
        $private = $this->path === '/setup' || $this->path === '/login' || str_starts_with($this->path, '/admin') || str_starts_with($this->path, '/api/');
        if ($private) {
            $this->session();
            if (!empty($_SESSION['admin'])) {
                $credential = $this->store->one('SELECT password FROM admins WHERE id=1');
                if (!$credential || !hash_equals(hash('sha256', $credential['password']), (string)($_SESSION['auth_hash'] ?? ''))) {
                    unset($_SESSION['admin'],$_SESSION['auth_hash']);
                }
            }
            if (in_array($method, ['POST','PUT'], true)) {
                $this->csrf();
            }
        } elseif (!in_array($method, ['GET','HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }
        if ($this->path === '/setup') {
            if ($this->settings) {
                $this->redirect('/login');
            }
            if ($method === 'POST') {
                $this->setup();
                return;
            }
            $this->view('setup', ['title' => 'Welcome to Neo','checks' => $this->checks()]);
            return;
        }
        if ($this->path === '/login') {
            if ($method === 'POST') {
                $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
                $blocked = $this->store->transaction(function () use ($key): bool {
                    $row = $this->store->one('SELECT * FROM login_attempts WHERE key=?', [$key]);
                    if (!$row || time() - (int)$row['started'] > 900) {
                        $this->store->run('INSERT INTO login_attempts VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=1,started=excluded.started', [$key,time()]);
                        return false;
                    }
                    $this->store->run('UPDATE login_attempts SET count=count+1 WHERE key=?', [$key]);
                    return (int)$row['count'] >= 10;
                });
                if ($blocked) {
                    throw new \RuntimeException('Too many login attempts. Try again in 15 minutes.');
                }
                $a = $this->store->one('SELECT * FROM admins WHERE id=1');
                if (!hash_equals($a['username'], (string)($_POST['username'] ?? '')) || !password_verify((string)($_POST['password'] ?? ''), $a['password'])) {
                    throw new \RuntimeException('Incorrect username or password.');
                }
                $this->store->run('DELETE FROM login_attempts WHERE key=?', [$key]);
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['auth_hash'] = hash('sha256', $a['password']);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $this->redirect('/admin');
            }
            $this->view('login', ['title' => 'Sign in']);
            return;
        }
        if ($private && empty($_SESSION['admin'])) {
            if (str_starts_with($this->path, '/api/')) {
                http_response_code(401);
                $this->json(['error' => 'Sign in to continue.']);
                return;
            }
            $this->redirect('/login');
        }
        if (str_starts_with($this->path, '/api/')) {
            $this->api($method);
            return;
        }
        if (preg_match('~^/admin/media/([a-f0-9-]{36})$~D', $this->path, $m) && in_array($method, ['GET','HEAD'], true)) {
            $a = $this->store->one('SELECT a.* FROM assets a JOIN episodes e ON e.id=a.episode_id WHERE a.id=? AND (e.audio_id=a.id OR e.video_id=a.id)', [$m[1]]);
            if (!$a) {
                $this->notFound();
                return;
            }
            session_write_close();
            Http::file($this->store->root.'/assets/'.$a['filename'], $a['mime'], true);
            return;
        }
        if ($this->path === '/admin/logout' && $method === 'POST') {
            $_SESSION = [];
            session_destroy();
            $this->redirect('/login');
        }
        if ($this->path === '/admin/settings') {
            if ($method === 'POST') {
                $this->saveSettings();
                $this->redirect('/admin/settings');
            }
            $this->view('settings', ['title' => 'Show settings']);
            return;
        }
        if ($this->path === '/admin/distribution') {
            $this->view('distribution', ['title' => 'Distribution','checks' => $this->checks(),'published' => (int)$this->store->one("SELECT COUNT(*) n FROM episodes WHERE state='published'")['n']]);
            return;
        }
        if ($this->path === '/admin/episodes/new') {
            if ($method === 'POST') {
                $id = (new Publishing($this->store))->save(null, $_POST);
                $this->redirect('/admin/episodes/'.$id);
            }
            $this->view('editor', ['title' => 'New episode','episode' => [],'jobs' => []]);
            return;
        }
        if (preg_match('~^/admin/episodes/([a-f0-9-]{36})(?:/(publish|unpublish|delete|retry))?$~D', $this->path, $m)) {
            $e = $this->store->one('SELECT * FROM episodes WHERE id=?', [$m[1]]);
            if (!$e) {
                $this->notFound();
                return;
            }
            if ($method === 'POST') {
                $pub = new Publishing($this->store);
                switch ($m[2] ?? 'save') {
                    case 'publish': $pub->publish($e['id']);
                        break;
                    case 'unpublish': $this->store->run("UPDATE episodes SET state='draft',updated_at=? WHERE id=?", [time(),$e['id']]);
                        break;
                    case 'delete': $pub->delete($e['id']);
                        $this->redirect('/admin');
                        // no break
                    case 'retry': $this->store->transaction(function () use ($e): void {
                        if ($this->store->one("SELECT id FROM jobs WHERE episode_id=? AND state IN ('queued','processing')", [$e['id']])) {
                            throw new \RuntimeException('A job is already active.');
                        }
                        $this->store->run("UPDATE jobs SET state='queued',error=NULL,updated_at=? WHERE episode_id=? AND state='failed'", [time(),$e['id']]);
                    });
                        if (Config::shared()) {
                            $job = $this->store->one("SELECT upload_id FROM jobs WHERE episode_id=? AND state='queued'", [$e['id']]);
                            if ($job) {
                                (new Worker($this->store))->once($job['upload_id']);
                            }
                        }
                        break;
                    default: $pub->save($e['id'], $_POST);
                }
                $this->redirect('/admin/episodes/'.$e['id']);
            }
            if (isset($m[2])) {
                http_response_code(405);
                return;
            }
            $jobs = $this->store->all('SELECT * FROM jobs WHERE episode_id=? ORDER BY created_at DESC,rowid DESC', [$e['id']]);
            $this->view('editor', ['title' => $e['title'],'episode' => $e,'jobs' => $jobs]);
            return;
        }
        if ($this->path === '/admin') {
            $rows = $this->store->all('SELECT e.*, (SELECT state FROM jobs j WHERE j.episode_id=e.id ORDER BY created_at DESC,rowid DESC LIMIT 1) processing FROM episodes e ORDER BY created_at DESC');
            $this->view('admin', ['title' => 'Your episodes','episodes' => $rows,'checks' => $this->checks()]);
            return;
        }
        if (preg_match('~^/feeds/(audio|video)\.xml$~D', $this->path, $m)) {
            Http::cached((new Feed($this->store))->render($m[1]), 'application/rss+xml; charset=UTF-8');
            return;
        }
        if (preg_match('~^/media/([a-f0-9-]{36})$~D', $this->path, $m)) {
            $a = $this->store->one("SELECT a.* FROM assets a JOIN episodes e ON e.id=a.episode_id WHERE a.id=? AND e.state='published' AND (e.audio_id=a.id OR e.video_id=a.id)", [$m[1]]);
            if (!$a) {
                $this->notFound();
                return;
            }
            Http::file($this->store->root.'/assets/'.$a['filename'], $a['mime']);
            return;
        }
        if (preg_match('~^/artwork/([a-f0-9-]{36}\.(?:jpg|png))$~D', $this->path, $m) && $m[1] === $this->settings['artwork']) {
            Http::file($this->store->root.'/assets/'.$m[1], str_ends_with($m[1], '.png') ? 'image/png' : 'image/jpeg');
            return;
        }
        if (preg_match('~^/episodes/([a-f0-9-]{36})$~D', $this->path, $m)) {
            $e = $this->store->one("SELECT * FROM episodes WHERE id=? AND state='published'", [$m[1]]);
            if (!$e) {
                $this->notFound();
                return;
            }
            $this->view('episode', ['title' => $e['title'],'episode' => $e,'assets' => $this->store->all('SELECT * FROM assets WHERE id=? OR id=?', [$e['audio_id'],$e['video_id']])]);
            return;
        }
        if ($this->path === '/') {
            $search = substr(trim((string)($_GET['q'] ?? '')), 0, 200);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $query = "FROM episodes WHERE state='published' AND (title LIKE ? OR description LIKE ?)";
            $args = ['%'.$search.'%','%'.$search.'%'];
            $count = (int)$this->store->one('SELECT COUNT(*) n '.$query, $args)['n'];
            $page = min($page, max(1, (int)ceil($count / 12)));
            $episodes = $this->store->all('SELECT * '.$query.' ORDER BY published_at DESC,id LIMIT 12 OFFSET '.(($page - 1) * 12), $args);
            $this->view('home', ['title' => $this->settings['title'],'episodes' => $episodes,'search' => $search,'page' => $page,'pages' => (int)ceil($count / 12)]);
            return;
        }
        $this->notFound();
    }
    private function notFound(): void
    {
        http_response_code(404);
        $this->view('message', ['title' => 'Page not found','message' => 'This page or media file is unavailable.']);
    }
    private function checks(): array
    {
        $health = $this->store->one('SELECT heartbeat FROM health WHERE id=1');
        return ['storage' => is_writable($this->store->root),'worker' => $health && time() - (int)$health['heartbeat'] < 30,'https' => str_starts_with($this->settings['base_url'] ?? '', 'https://'),'artwork' => !empty($this->settings['artwork'])];
    }
    private function settingsInput(): array
    {
        $s = [];
        foreach (['title','description','base_url','owner_name','owner_email','language','category'] as $key) {
            $s[$key] = Text::clean((string)($_POST[$key] ?? ''), true);
        }
        $s['base_url'] = rtrim($s['base_url'], '/');
        $url = parse_url($s['base_url']);
        if (!filter_var($s['base_url'], FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https' || isset($url['query'],$url['fragment']) || isset($url['user']) || isset($url['pass']) || rtrim($url['path'] ?? '', '/') !== Config::basePath() || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('Public URL must use HTTPS and match the configured installation path, without credentials, query, or fragment.');
        }
        if (!$s['title'] || !$s['description'] || !$s['owner_name'] || !filter_var($s['owner_email'], FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $s['language'])) {
            throw new \InvalidArgumentException('Complete the show information with a valid owner email and language code.');
        }
        foreach ($s as $key => $v) {
            if (strlen($v) > ($key === 'description' ? 50000 : 300)) {
                throw new \InvalidArgumentException('Show field is too long.');
            }
        }
        if (!in_array($s['category'], self::categories(), true)) {
            throw new \InvalidArgumentException('Choose a listed podcast category.');
        }
        $s['explicit'] = isset($_POST['explicit']) ? 1 : 0;
        return $s;
    }
    public static function categories(): array
    {
        return ['Arts','Business','Comedy','Education','Fiction','Government','Health & Fitness','History','Kids & Family','Leisure','Music','News','Religion & Spirituality','Science','Society & Culture','Sports','Technology','True Crime','TV & Film'];
    }
    private function setup(): void
    {
        $secret = \Neo\Config::get('NEO_SETUP_TOKEN') ?: '';
        if (strlen($secret) < 20 || !hash_equals($secret, (string)($_POST['setup_token'] ?? ''))) {
            throw new \InvalidArgumentException('Enter the setup token configured on the server (at least 20 characters).');
        }
        $s = $this->settingsInput();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username === '' || strlen($username) > 100 || strlen($password) < 12 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Choose a username and a password of 12–72 bytes.');
        }
        if (!Config::shared()) {
            if (!$this->checks()['worker']) {
                throw new \RuntimeException('Start the background worker before completing setup.');
            }
            Process::run([Config::get('FFPROBE_BIN') ?: 'ffprobe','-version']);
            $encoders = Process::run([Config::get('FFMPEG_BIN') ?: 'ffmpeg','-hide_banner','-encoders']);
            if (!str_contains($encoders, 'libmp3lame')) {
                throw new \RuntimeException('FFmpeg requires the libmp3lame encoder.');
            }
        }
        $this->store->transaction(function () use ($s, $username, $password): void {
            if ($this->store->settings()) {
                throw new \RuntimeException('Setup has already completed.');
            }
            $this->store->run('INSERT INTO settings(id,title,description,base_url,owner_name,owner_email,language,category,explicit,updated_at) VALUES(1,?,?,?,?,?,?,?,?,?)', [...array_values($s),time()]);
            $this->store->run('INSERT INTO admins VALUES(1,?,?)', [$username,password_hash($password, PASSWORD_DEFAULT)]);
        });
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['auth_hash'] = hash('sha256', $this->store->one('SELECT password FROM admins WHERE id=1')['password']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->redirect('/admin/settings');
    }
    private function saveSettings(): void
    {
        $s = $this->settingsInput();
        // Keep the new file and its reference together relative to garbage collection.
        $this->store->transaction(function () use ($s): void {
            $art = $this->store->settings()['artwork'];
            if (isset($_FILES['artwork']) && $_FILES['artwork']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['artwork'];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5000000 || !is_uploaded_file($file['tmp_name'])) {
                    throw new \InvalidArgumentException('Upload a JPG or PNG cover under 5 MB.');
                }
                $info = @getimagesize($file['tmp_name']);
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG,IMAGETYPE_PNG], true) || $info[0] !== $info[1] || $info[0] < 1400 || $info[0] > 3000) {
                    throw new \InvalidArgumentException('Artwork must be a square JPG/PNG, 1400–3000 pixels on each side.');
                }
                $art = Store::uuid().($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
                if (!move_uploaded_file($file['tmp_name'], $this->store->root.'/assets/'.$art)) {
                    throw new \RuntimeException('Unable to save artwork.');
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($this->store->root.'/assets/'.$art, 0644);
                }
            }
            $this->store->run('UPDATE settings SET title=?,description=?,base_url=?,owner_name=?,owner_email=?,language=?,category=?,explicit=?,artwork=?,updated_at=? WHERE id=1', [...array_values($s),$art,time()]);
        });
    }
    private function api(string $method): void
    {
        $uploads = new Uploads($this->store);
        if ($this->path === '/api/uploads' && $method === 'POST') {
            $i = $this->input();
            $this->json($uploads->create((string)($i['episode_id'] ?? ''), (string)($i['name'] ?? ''), (int)($i['size'] ?? 0)));
            return;
        }
        if (preg_match('~^/api/uploads/([a-f0-9-]{36})(?:/(finish))?$~D', $this->path, $m)) {
            if (isset($m[2]) && $method === 'POST') {
                $this->json($uploads->finish($m[1]));
                return;
            }
            if (!isset($m[2]) && $method === 'GET') {
                $this->json($uploads->get($m[1]));
                return;
            }
            if (!isset($m[2]) && $method === 'PUT') {
                $offset = $_SERVER['HTTP_UPLOAD_OFFSET'] ?? '';
                if (!ctype_digit($offset)) {
                    throw new \InvalidArgumentException('Upload-Offset must be a nonnegative integer.');
                }
                $chunk = file_get_contents('php://input', false, null, 0, Config::chunkSize() + 1);
                $next = $uploads->append($m[1], (int)$offset, $chunk);
                $this->json(['offset' => $next]);
                return;
            }
            http_response_code(405);
            return;
        }
        if (preg_match('~^/api/episodes/([a-f0-9-]{36})/status$~D', $this->path, $m) && $method === 'GET') {
            $e = $this->store->one('SELECT id,audio_id,video_id FROM episodes WHERE id=?', [$m[1]]);
            if (!$e) {
                http_response_code(404);
                $this->json(['error' => 'Episode not found.']);
                return;
            }
            // Authenticated polling recovers interrupted shared-hosting requests.
            if (Config::shared()) {
                $pending = $this->store->one("SELECT upload_id FROM jobs WHERE episode_id=? AND state IN ('queued','processing')", [$m[1]]);
                if ($pending) {
                    (new Worker($this->store))->once($pending['upload_id']);
                }
            }
            $j = $this->store->one('SELECT state,error FROM jobs WHERE episode_id=? ORDER BY created_at DESC,rowid DESC LIMIT 1', [$m[1]]);
            $this->json(['state' => $j['state'] ?? ($e['audio_id'] ? 'ready' : 'empty'),'error' => $j['error'] ?? null]);
            return;
        }
        http_response_code(404);
        $this->json(['error' => 'Endpoint not found.']);
    }
}
