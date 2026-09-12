<?php

declare(strict_types=1);

namespace Neo;

final class Maintenance
{
    public function __construct(private Store $store)
    {
    }
    public function backup(string $destination): void
    {
        if ($destination === '' || file_exists($destination)) {
            throw new \InvalidArgumentException('Backup destination must be a new directory.');
        }
        $lock = fopen($this->store->root.'/worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            throw new \RuntimeException('Worker is busy. Retry backup after processing completes.');
        }
        try {
            if (!mkdir($destination, 0700, true)) {
                throw new \RuntimeException('Cannot create backup directory.');
            }
            mkdir($destination.'/assets', 0700);
            mkdir($destination.'/uploads', 0700);
            $database = $destination.'/neo.sqlite';
            $this->store->run('VACUUM INTO ?', [$database]);
            $snapshot = new \PDO('sqlite:'.$database, null, null, [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            $assets = $snapshot->query('SELECT filename FROM assets')->fetchAll(\PDO::FETCH_COLUMN);
            $art = $snapshot->query('SELECT artwork FROM settings WHERE id=1')->fetchColumn();
            if ($art) {
                $assets[] = $art;
            }
            foreach ($assets as $name) {
                if (!copy($this->store->root.'/assets/'.$name, $destination.'/assets/'.$name)) {
                    throw new \RuntimeException('Backup media copy failed.');
                }
            }
            foreach ($snapshot->query("SELECT id,offset FROM uploads WHERE status <> 'complete'") as $u) {
                $name = $u['id'].'.part';
                if ((int)$u['offset'] > 0 && !copy($this->store->root.'/uploads/'.$name, $destination.'/uploads/'.$name)) {
                    throw new \RuntimeException('Backup upload copy failed.');
                }
            }
            if (file_put_contents($destination.'/BACKUP-COMPLETE', gmdate(DATE_ATOM)."\nNeo Podcast Generator v1\n") === false) {
                throw new \RuntimeException('Could not finalize the backup completion marker.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public function garbageCollect(bool $apply = false): array
    {
        $lock = fopen($this->store->root.'/worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            throw new \RuntimeException('Worker is busy. Retry after processing completes.');
        }
        try {
            return $this->store->transaction(function () use ($apply): array {
                $used = $this->store->all('SELECT a.filename FROM assets a JOIN episodes e ON a.id=e.audio_id OR a.id=e.video_id');
                $keep = array_column($used, 'filename');
                $art = $this->store->settings()['artwork'] ?? null;
                if ($art) {
                    $keep[] = $art;
                }
                $active = $this->store->all("SELECT u.id FROM uploads u WHERE u.status <> 'complete'");
                $uploadKeep = array_map(static fn ($u) => $u['id'].'.part', $active);
                $removed = [];
                foreach (['assets' => $keep,'uploads' => $uploadKeep] as $dir => $names) {
                    foreach (glob($this->store->root.'/'.$dir.'/*') ?: [] as $path) {
                        if (!is_file($path) || is_link($path) || in_array(basename($path), $names, true)) {
                            continue;
                        }
                        $removed[] = $dir.'/'.basename($path);
                        if ($apply && !unlink($path)) {
                            throw new \RuntimeException('Unable to remove unused file.');
                        }
                    }
                }
                if ($apply) {
                    $this->store->run('DELETE FROM assets WHERE id NOT IN (SELECT audio_id FROM episodes WHERE audio_id IS NOT NULL UNION SELECT video_id FROM episodes WHERE video_id IS NOT NULL)');
                }
                return $removed;
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
