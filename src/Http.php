<?php

declare(strict_types=1);

namespace Neo;

final class Http
{
    public static function cached(string $body, string $type): void
    {
        $etag = '"'.hash('sha256', $body).'"';
        header('ETag: '.$etag);
        header('Cache-Control: public, max-age=60');
        header('Content-Type: '.$type);
        if (in_array($etag, array_map('trim', explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), true)) {
            http_response_code(304);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            echo $body;
        }
    }
    public static function file(string $path, string $mime, bool $private = false): void
    {
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }
        if (getenv('NEO_X_ACCEL') === '1') {
            header('Content-Type: '.$mime);
            header('Cache-Control: '.($private ? 'private, no-store' : 'public, max-age=3600'));
            header('X-Accel-Redirect: /_protected/'.rawurlencode(basename($path)));
            return;
        }
        $size = filesize($path);
        $mtime = filemtime($path);
        $etag = '"'.dechex($mtime).'-'.dechex($size).'"';
        header('Content-Type: '.$mime);
        header('Accept-Ranges: bytes');
        header('ETag: '.$etag);
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
        header('Cache-Control: '.($private ? 'private, no-store' : 'public, max-age=3600'));
        if (!$private && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        if (!$private && !isset($_SERVER['HTTP_IF_NONE_MATCH']) && isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
            http_response_code(304);
            return;
        }
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        $ifRange = $_SERVER['HTTP_IF_RANGE'] ?? null;
        if ($ifRange !== null && $ifRange !== $etag && (strtotime($ifRange) ?: 0) < $mtime) {
            $range = '';
        }
        if ($range !== '' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) || ($m[1] === '' && $m[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */'.$size);
                return;
            }
            if ($m[1] === '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                $end = $m[2] === '' ? $end : min($end, (int)$m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */'.$size);
                return;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: '.($end - $start + 1));
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
            return;
        }
        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
            $part = fread($fp, min(1048576, $remaining));
            if ($part === false || $part === '') {
                break;
            } echo $part;
            $remaining -= strlen($part);
        }
        fclose($fp);
    }
}
