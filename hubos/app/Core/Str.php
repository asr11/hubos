<?php

declare(strict_types=1);

namespace HubOS\Core;

/** UTF-8 string helpers that work without mbstring (PCRE /u fallback). */
final class Str
{
    public static function len(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $n = preg_match_all('/./su', $s);
        return $n === false ? strlen($s) : $n;
    }

    public static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    public static function first(string $s): string
    {
        return preg_match('/^./su', $s, $m) === 1 ? $m[0] : '';
    }

    /** Trimmed, valid UTF-8, control characters removed (keeps newlines out). */
    public static function clean(string $s): string
    {
        if (!self::isUtf8($s)) {
            return '';
        }
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $s));
    }
}
