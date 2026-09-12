<?php

declare(strict_types=1);

namespace Neo;

final class Publishing
{
    public function __construct(private Store $store)
    {
    }
    public function save(?string $id, array $input): string
    {
        $title = Text::clean((string)($input['title'] ?? ''));
        $description = Text::clean((string)($input['description'] ?? ''), true);
        if ($title === '' || strlen($title) > 300 || strlen($description) > 50000) {
            throw new \InvalidArgumentException('Enter a title (up to 300 characters) and a description up to 50,000 characters.');
        }
        $type = $input['episode_type'] ?? 'full';
        if (!in_array($type, ['full','trailer','bonus'], true)) {
            throw new \InvalidArgumentException('Invalid episode type.');
        }
        $numbers = [];
        foreach (['season','number'] as $key) {
            $value = $input[$key] ?? '';
            if ($value !== '' && (!ctype_digit((string)$value) || (int)$value < 1 || (int)$value > 1000000)) {
                throw new \InvalidArgumentException('Season and episode numbers must be positive integers.');
            }
            $numbers[] = $value === '' ? null : (int)$value;
        }
        $args = [$title,$description,isset($input['explicit']) ? 1 : 0,...$numbers,$type,time()];
        if ($id) {
            if (!$this->store->one('SELECT id FROM episodes WHERE id=?', [$id])) {
                throw new \InvalidArgumentException('Episode not found.');
            }
            $this->store->run('UPDATE episodes SET title=?,description=?,explicit=?,season=?,number=?,episode_type=?,updated_at=? WHERE id=?', [...$args,$id]);
        } else {
            $id = Store::uuid();
            $this->store->run('INSERT INTO episodes(title,description,explicit,season,number,episode_type,updated_at,id,created_at) VALUES(?,?,?,?,?,?,?,?,?)', [...$args,$id,time()]);
        }
        return $id;
    }
    public function publish(string $id): void
    {
        $this->store->transaction(function () use ($id): void {
            $e = $this->store->one('SELECT * FROM episodes WHERE id=?', [$id]);
            if (!$e || !$e['audio_id'] || trim($e['description']) === '') {
                throw new \RuntimeException('Publication requires ready audio, a title, and a description.');
            }
            if ($this->store->one("SELECT id FROM jobs WHERE episode_id=? AND state IN ('queued','processing','failed')", [$id])) {
                throw new \RuntimeException('Resolve pending or failed media processing before publishing.');
            }
            foreach (array_filter([$e['audio_id'],$e['video_id']]) as $asset) {
                $a = $this->store->one('SELECT * FROM assets WHERE id=? AND episode_id=?', [$asset,$id]);
                if (!$a || !is_file($this->store->root.'/assets/'.$a['filename'])) {
                    throw new \RuntimeException('Required media is missing. Upload the media again.');
                }
            }
            $this->store->run("UPDATE episodes SET state='published',published_at=COALESCE(published_at,?),updated_at=? WHERE id=?", [time(),time(),$id]);
        });
    }
    public function delete(string $id): void
    {
        // Files are left for offline garbage collection; an in-flight worker cannot publish a deleted row.
        $this->store->run('DELETE FROM episodes WHERE id=?', [$id]);
    }
}
