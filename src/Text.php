<?php

declare(strict_types=1);

namespace Neo;

final class Text
{
    public static function clean(string $text, bool $stripHtml = false): string
    {
        if (!preg_match('//u', $text)) {
            throw new \InvalidArgumentException('Text must use valid UTF-8 encoding.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $text)) {
            throw new \InvalidArgumentException('Text contains unsupported control characters.');
        }
        return trim($stripHtml ? strip_tags($text) : $text);
    }
}
