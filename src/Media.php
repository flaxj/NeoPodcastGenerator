<?php

declare(strict_types=1);

namespace Neo;

final class Media
{
    public function probe(string $path, string $extension): array
    {
        $data = json_decode(Process::run([getenv('FFPROBE_BIN') ?: 'ffprobe', '-v','error','-protocol_whitelist','file,pipe','-format_whitelist','mp3,mov','-show_format','-show_streams','-of','json', $path]), true, 512, JSON_THROW_ON_ERROR);
        $audio = array_values(array_filter($data['streams'] ?? [], static fn ($s) => ($s['codec_type'] ?? '') === 'audio'));
        $video = array_values(array_filter($data['streams'] ?? [], static fn ($s) => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic'])));
        $duration = (float)($data['format']['duration'] ?? 0);
        $format = explode(',', $data['format']['format_name'] ?? '');
        if (!$audio || $duration <= 0 || !is_finite($duration)) {
            throw new \RuntimeException('File must contain a usable audio track and a positive duration.');
        }
        if ($extension === 'mp3') {
            if (!in_array('mp3', $format, true) || $audio[0]['codec_name'] !== 'mp3' || $video || ($audio[0]['channels'] ?? 0) > 2) {
                throw new \RuntimeException('Audio-only uploads must be genuine mono or stereo MP3 files.');
            }
        } elseif (!in_array($extension, ['mov','mp4'], true) || !in_array('mov', $format, true) || !$video) {
            throw new \RuntimeException('Video uploads must be genuine MOV or MP4 files with video and audio tracks.');
        }
        $selected = $audio[0];
        foreach ($audio as $stream) {
            if (!empty($stream['disposition']['default'])) {
                $selected = $stream;
                break;
            }
        }
        return ['duration' => $duration, 'track' => (int)$selected['index'], 'mime' => ['mp3' => 'audio/mpeg','mp4' => 'video/mp4','mov' => 'video/quicktime'][$extension]];
    }
    public function extract(string $source, string $destination, int $track, callable $tick): void
    {
        Process::run([getenv('FFMPEG_BIN') ?: 'ffmpeg','-nostdin','-v','error','-y','-protocol_whitelist','file,pipe','-format_whitelist','mov','-i',$source,'-map','0:'.$track,'-vn','-map_metadata','-1','-c:a','libmp3lame','-b:a','192k','-ar','44100','-ac','2','-f','mp3',$destination], 21600, $tick);
    }
}
