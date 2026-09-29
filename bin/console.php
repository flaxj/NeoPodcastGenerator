<?php

declare(strict_types=1);
$store = require dirname(__DIR__).'/bootstrap.php';
try {
    switch ($argv[1] ?? 'help') {
        case 'check':
            $heartbeat = $store->one('SELECT heartbeat FROM health WHERE id=1');
            $worker = $heartbeat && time() - (int)$heartbeat['heartbeat'] < 30;
            echo json_encode(['storage' => is_writable($store->root),'worker_online' => (bool)$worker,'schema' => $store->all('SELECT version FROM migrations'),'free_bytes' => disk_free_space($store->root)], JSON_PRETTY_PRINT)."\n";
            exit((Neo\Config::shared() || $worker) && is_writable($store->root) ? 0 : 1);
        case 'backup':
            (new Neo\Maintenance($store))->backup($argv[2] ?? '');
            echo "Backup complete. Copy this directory off the server.\n";
            break;
        case 'gc':
            $apply = in_array('--apply', $argv, true);
            $removed = (new Neo\Maintenance($store))->garbageCollect($apply);
            echo ($apply ? 'Removed' : 'Would remove').': '.count($removed)." unused files\n".implode("\n", $removed)."\n";
            break;
        case 'reset-password':
            if (!$store->one('SELECT id FROM admins WHERE id=1')) {
                throw new RuntimeException('Complete setup first.');
            }
            $password = rtrim(stream_get_contents(STDIN), "\r\n");
            if (strlen($password) < 12 || strlen($password) > 72) {
                throw new InvalidArgumentException('Supply a 12–72 byte password through standard input.');
            }
            $store->run('UPDATE admins SET password=? WHERE id=1', [password_hash($password, PASSWORD_DEFAULT)]);
            echo "Password updated. Existing administrator sessions are now invalid.\n";
            break;
        default: echo "Commands:\n  check\n  backup <new-directory>\n  gc [--apply]\n  reset-password < password-file\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
