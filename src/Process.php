<?php

declare(strict_types=1);

namespace Neo;

final class Process
{
    public static function run(array $args, int $timeout = 120, ?callable $tick = null): string
    {
        $process = proc_open($args, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start media processor.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $error = '';
        $start = time();
        $exit = -1;
        try {
            do {
                $out .= stream_get_contents($pipes[1]);
                $error = substr($error . stream_get_contents($pipes[2]), -8000);
                if (strlen($out) > 2000000) {
                    throw new \RuntimeException('Media probe output exceeded limit.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (time() - $start > $timeout) {
                    throw new \RuntimeException('Media processing timed out. Retry with a smaller file.');
                }
                if ($tick) {
                    $tick();
                }
                usleep(100000);
            } while (true);
            $out .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if ($exit !== 0) {
            throw new \RuntimeException('Media processing failed. Check that the file is complete and its codecs are supported. ' . substr(trim($error), -1000));
        }
        return $out;
    }
}
