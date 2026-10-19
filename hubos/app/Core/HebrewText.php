<?php

declare(strict_types=1);

namespace HubOS\Core;

/**
 * Hebrew-safe text normalization and matching (no mbstring required).
 *
 * SQL LIKE is not enough for Hebrew: a word is often glued to a one-letter
 * prefix (ו/ה/ב/כ/ל/מ/ש — "ולמנו", "בספנות"). We normalize both sides
 * (niqqud, final letters, quotes/geresh, punctuation, case) and, as an
 * alternative match, strip ONE leading prefix letter from the query token
 * and from the haystack words.
 */
final class HebrewText
{
    /** One-letter prefixes (אותיות שימוש) that may be glued to a word. */
    public const PREFIXES = ['ו', 'ה', 'ב', 'כ', 'ל', 'מ', 'ש'];

    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** Minimal length (in letters) of a word after stripping a prefix. */
    private const MIN_STRIPPED = 3;

    public static function normalize(string $s): string
    {
        if (!Str::isUtf8($s)) {
            return '';
        }
        $s = strtolower($s); // ASCII-only in PHP 8.2+, safe for UTF-8
        $s = (string) preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);           // niqqud + cantillation
        $s = strtr($s, self::FINALS);
        $s = (string) preg_replace('/["\'`~\x{05F3}\x{05F4}\x{2018}\x{2019}\x{201C}\x{201D}]/u', '', $s); // quotes, geresh, gershayim
        $s = (string) preg_replace('/[^\p{L}\p{N}@.]+/u', ' ', $s);
        $s = (string) preg_replace('/(?<!\S)\.+|\.+(?!\S)/u', ' ', $s);          // dots only inside tokens (emails, domains)
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** @return list<string> */
    public static function tokens(string $s): array
    {
        $n = self::normalize($s);
        return $n === '' ? [] : explode(' ', $n);
    }

    /** The word without ONE leading prefix letter, or null if not applicable. */
    public static function stripPrefix(string $word): ?string
    {
        $first = Str::first($word);
        if ($first === '' || !in_array($first, self::PREFIXES, true)) {
            return null;
        }
        $rest = substr($word, strlen($first));
        return Str::len($rest) >= self::MIN_STRIPPED ? $rest : null;
    }

    /**
     * Relevance score of $query against $haystack (0 = no match). Every query
     * token must match: 4 exact word, 3 word prefix or exact word after
     * stripping one prefix letter, 2 prefix match after stripping, 1 substring.
     */
    public static function score(string $query, string $haystack): int
    {
        $qTokens = self::tokens($query);
        if ($qTokens === []) {
            return 0;
        }
        $joined = self::normalize($haystack);
        $words = $joined === '' ? [] : explode(' ', $joined);
        $stripped = [];
        foreach ($words as $w) {
            $s = self::stripPrefix($w);
            if ($s !== null) {
                $stripped[] = $s;
            }
        }

        $total = 0;
        foreach ($qTokens as $qt) {
            $best = 0;
            foreach ($words as $w) {
                if ($w === $qt) {
                    $best = 4;
                    break;
                }
                if (str_starts_with($w, $qt)) {
                    $best = max($best, 3);
                }
            }
            if ($best < 3) {
                $qs = self::stripPrefix($qt);
                foreach ($words as $w) {
                    if ($qs !== null && ($w === $qs || str_starts_with($w, $qs))) {
                        $best = max($best, $w === $qs ? 3 : 2);
                    }
                }
                foreach ($stripped as $sw) {
                    if ($sw === $qt || ($qs !== null && $sw === $qs)) {
                        $best = max($best, 3);
                    } elseif (str_starts_with($sw, $qt) || ($qs !== null && str_starts_with($sw, $qs))) {
                        $best = max($best, 2);
                    }
                }
            }
            if ($best === 0 && $joined !== '' && str_contains($joined, $qt)) {
                $best = 1;
            }
            if ($best === 0) {
                return 0;
            }
            $total += $best;
        }
        return $total;
    }
}
