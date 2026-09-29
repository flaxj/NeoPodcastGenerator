<?php

declare(strict_types=1);

namespace Neo;

final class Worker
{
    public function __construct(private Store $store, private Media $media = new Media())
    {
    }
    public function heartbeat(): void
    {
        $this->store->run('INSERT INTO health(id,heartbeat) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET heartbeat=excluded.heartbeat', [time()]);
    }
    public function once(?string $uploadId = null): bool
    {
        // A process-wide filesystem lock prevents another worker reclaiming a live conversion.
        $lock = fopen($this->store->root.'/worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            } return false;
        }
        try {
            $this->heartbeat();
            $job = $this->store->transaction(function () use ($uploadId): ?array {
                $this->store->run("UPDATE jobs SET state='queued' WHERE state='processing'");
                $j = $uploadId === null ? $this->store->one("SELECT * FROM jobs WHERE state='queued' ORDER BY created_at,id LIMIT 1") : $this->store->one("SELECT * FROM jobs WHERE state='queued' AND upload_id=? LIMIT 1", [$uploadId]);
                if ($j) {
                    $this->store->run("UPDATE jobs SET state='processing',error=NULL,updated_at=? WHERE id=?", [time(),$j['id']]);
                }
                return $j;
            });
            if (!$job) {
                return false;
            }
            $created = [];
            try {
                $u = $this->store->one('SELECT * FROM uploads WHERE id=?', [$job['upload_id']]);
                if (!$u) {
                    return true;
                }
                $source = $this->store->root.'/uploads/'.$u['id'].'.part';
                $info = $this->media->probe($source, $u['extension']);
                $originalId = Store::uuid();
                $originalName = $originalId.'.'.$u['extension'];
                $original = $this->store->root.'/assets/'.$originalName;
                $created[] = $original;
                if (!copy($source, $original)) {
                    throw new \RuntimeException('Cannot finalize original media. Check disk space.');
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($original, 0644);
                }
                $rows = [[$originalId,$job['episode_id'],$u['extension'] === 'mp3' ? 'audio' : 'video',$originalName,$info['mime'],filesize($original),$info['duration']]];
                $audioId = $originalId;
                $videoId = null;
                if ($u['extension'] !== 'mp3') {
                    $videoId = $originalId;
                    $audioId = Store::uuid();
                    $audioName = $audioId.'.mp3';
                    $temp = $this->store->root.'/assets/'.$audioName.'.tmp';
                    $audio = $this->store->root.'/assets/'.$audioName;
                    $created[] = $temp;
                    $created[] = $audio;
                    $last = 0;
                    $this->media->extract($source, $temp, $info['track'], function () use (&$last): void {
                        if (time() - $last >= 5) {
                            $this->heartbeat();
                            $last = time();
                        }
                    });
                    $audioInfo = $this->media->probe($temp, 'mp3');
                    if (!rename($temp, $audio)) {
                        throw new \RuntimeException('Cannot finalize extracted audio.');
                    }
                    if (PHP_OS_FAMILY !== 'Windows') {
                        chmod($audio, 0644);
                    }
                    $rows[] = [$audioId,$job['episode_id'],'audio',$audioName,'audio/mpeg',filesize($audio),$audioInfo['duration']];
                }
                $this->store->transaction(function () use ($job, $rows, $audioId, $videoId): void {
                    if (!$this->store->one('SELECT id FROM episodes WHERE id=?', [$job['episode_id']])) {
                        throw new \RuntimeException('Episode was deleted.');
                    }
                    foreach ($rows as $row) {
                        $this->store->run('INSERT INTO assets VALUES(?,?,?,?,?,?,?)', $row);
                    }
                    $this->store->run('UPDATE episodes SET audio_id=?,video_id=?,updated_at=? WHERE id=?', [$audioId,$videoId,time(),$job['episode_id']]);
                    $this->store->run("UPDATE jobs SET state='ready',updated_at=? WHERE id=?", [time(),$job['id']]);
                    $this->store->run("UPDATE uploads SET status='complete' WHERE id=?", [$job['upload_id']]);
                });
                @unlink($source);
            } catch (\Throwable $e) {
                foreach ($created as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                $this->store->run("UPDATE jobs SET state='failed',error=?,updated_at=? WHERE id=?", [substr($e->getMessage(), 0, 2000),time(),$job['id']]);
            }
            $this->heartbeat();
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
