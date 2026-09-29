<?php

declare(strict_types=1);

namespace Neo;

final class Uploads
{
    public function __construct(private Store $store)
    {
    }
    public function create(string $episode, string $name, int $size): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, (Config::shared() ? ['mp3'] : ['mp3','mov','mp4']), true) || $size < 1 || $size > (int)(\Neo\Config::get('NEO_MAX_UPLOAD') ?: 10737418240)) {
            throw new \InvalidArgumentException(Config::shared() ? 'Choose an MP3 within the upload limit. Video conversion requires a server installation.' : 'Choose an MP3, MOV or MP4 within the configured upload limit.');
        }
        return $this->store->transaction(function () use ($episode, $ext, $size): array {
            if (!$this->store->one('SELECT id FROM episodes WHERE id=?', [$episode])) {
                throw new \InvalidArgumentException('Episode not found.');
            }
            $existing = $this->store->one("SELECT id FROM jobs WHERE episode_id=? AND state IN ('queued','processing')", [$episode]);
            if ($existing) {
                throw new \RuntimeException('Wait for the current media job to finish.');
            }
            $id = Store::uuid();
            $this->store->run('INSERT INTO uploads(id,episode_id,extension,total,created_at) VALUES(?,?,?,?,?)', [$id,$episode,$ext,$size,time()]);
            return ['id' => $id,'offset' => 0,'total' => $size];
        });
    }
    public function get(string $id): array
    {
        return $this->store->one('SELECT * FROM uploads WHERE id=?', [$id]) ?? throw new \InvalidArgumentException('Upload not found.');
    }
    public function append(string $id, int $offset, string $chunk): int
    {
        if (strlen($chunk) < 1 || strlen($chunk) > Config::chunkSize()) {
            throw new \InvalidArgumentException('Invalid chunk size.');
        }
        return $this->store->transaction(function () use ($id, $offset, $chunk): int {
            $u = $this->get($id);
            if ($u['status'] !== 'uploading' || $offset !== (int)$u['offset'] || $offset + strlen($chunk) > (int)$u['total']) {
                throw new \RuntimeException('Upload offset conflict. Resume from the server offset.');
            }
            $file = fopen($this->store->root.'/uploads/'.$u['id'].'.part', 'c+b');
            if (!$file) {
                throw new \RuntimeException('Unable to write upload.');
            }
            try {
                ftruncate($file, $offset);
                fseek($file, $offset);
                if (fwrite($file, $chunk) !== strlen($chunk) || !fflush($file)) {
                    throw new \RuntimeException('Disk write failed. Check available storage.');
                }
            } finally {
                fclose($file);
            }
            $next = $offset + strlen($chunk);
            $this->store->run('UPDATE uploads SET offset=? WHERE id=?', [$next,$id]);
            return $next;
        });
    }
    public function finish(string $id): array
    {
        $this->store->transaction(function () use ($id): void {
            $u = $this->get($id);
            if ($u['status'] !== 'uploading') {
                return;
            }
            if ((int)$u['offset'] !== (int)$u['total']) {
                throw new \RuntimeException('Upload is incomplete.');
            }
            $this->store->run("DELETE FROM jobs WHERE episode_id=? AND state='failed'", [$u['episode_id']]);
            $this->store->run('INSERT INTO jobs(id,episode_id,upload_id,created_at,updated_at) VALUES(?,?,?,?,?)', [Store::uuid(),$u['episode_id'],$id,time(),time()]);
            $this->store->run("UPDATE uploads SET status='queued' WHERE id=?", [$id]);
        });
        if (Config::shared()) {
            (new Worker($this->store))->once($id);
        }
        $job = $this->store->one('SELECT state,error FROM jobs WHERE upload_id=?', [$id]);
        return ['queued' => in_array($job['state'] ?? '', ['queued','processing'], true), 'state' => $job['state'] ?? 'empty', 'error' => $job['error'] ?? null];
    }
}
