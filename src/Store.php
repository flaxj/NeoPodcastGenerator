<?php

declare(strict_types=1);

namespace Neo;

use PDO;
use Throwable;

final class Store
{
    public PDO $db;
    public function __construct(public readonly string $root)
    {
        foreach (['' => 0755, '/assets' => 0755, '/uploads' => 0700] as $dir => $mode) {
            if (!is_dir($root . $dir) && !mkdir($root . $dir, $mode, true) && !is_dir($root . $dir)) {
                throw new \RuntimeException('Storage is not writable.');
            }
        }
        $this->db = new PDO('sqlite:' . $root . '/neo.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($root . '/neo.sqlite', 0600);
        }
        $this->db->exec('PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE IF NOT EXISTS migrations(version INTEGER PRIMARY KEY)');
            if (!$this->one('SELECT version FROM migrations WHERE version=1')) {
                $this->db->exec(file_get_contents(__DIR__ . '/../migrations/001.sql'));
                $this->run('INSERT INTO migrations VALUES(1)');
            }
        });
    }
    public function run(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }
    public function one(string $sql, array $args = []): ?array
    {
        return $this->run($sql, $args)->fetch() ?: null;
    }
    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll();
    }
    public function transaction(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    public function settings(): array
    {
        return $this->one('SELECT * FROM settings WHERE id=1') ?? [];
    }
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $h = bin2hex($bytes);
        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);
    }
}
