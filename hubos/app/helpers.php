<?php

declare(strict_types=1);

use HubOS\Core\View;

if (!function_exists('e')) {
    /** HTML-escape any scalar for safe output. */
    function e(mixed $value): string
    {
        return View::e($value);
    }
}

if (!function_exists('fmt_dt')) {
    /** Format a stored UTC ISO timestamp for display in Israel time. */
    function fmt_dt(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone('Asia/Jerusalem'))
                ->format('d/m/Y H:i');
        } catch (Throwable) {
            return '';
        }
    }
}

if (!function_exists('fmt_money')) {
    /** ₪ amount with thousands separators. */
    function fmt_money(float|int|string|null $amount, int $decimals = 0): string
    {
        $n = (float) $amount;
        return ($n < 0 ? '-' : '') . '₪' . number_format(abs($n), $decimals);
    }
}

if (!function_exists('fmt_hours')) {
    function fmt_hours(float|int|null $hours): string
    {
        return number_format((float) $hours, 2);
    }
}

if (!function_exists('fmt_date')) {
    /** 'YYYY-MM-DD…' → 'DD/MM/YYYY' (no timezone shift for plain dates). */
    function fmt_date(?string $date): string
    {
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) !== 1) {
            return (string) $date;
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
}

if (!function_exists('fmt_inline_bidi')) {
    /**
     * Escape plain text safely while isolating URLs, emails, and domain names
     * in <bdi dir="ltr"> (or safe external links) so RTL Hebrew lines never break mid-domain.
     */
    function fmt_inline_bidi(string $line): string
    {
        $pattern = '/(https?:\/\/[^\s<>"\'\)\]\x{0590}-\x{05FF}]+|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}|(?<![A-Za-z0-9])(?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+(?:co\.il|org\.il|net\.il|gov\.il|ac\.il|muni\.il|com|net|org|io|dev|cloud|ai)\b|#\d+\b)/u';
        $parts = preg_split($pattern, $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return e($line);
        }
        $out = '';
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($i % 2 === 1) {
                $trail = '';
                while ($part !== '' && str_contains('.,;:!?)]', substr($part, -1))) {
                    $trail = substr($part, -1) . $trail;
                    $part = substr($part, 0, -1);
                }
                if (str_starts_with($part, 'http://') || str_starts_with($part, 'https://')) {
                    $esc = e($part);
                    $out .= '<a href="' . $esc . '" target="_blank" rel="noopener noreferrer" dir="ltr" class="ltr-link">' . $esc . '</a>' . e($trail);
                } else {
                    $out .= '<bdi dir="ltr" class="ltr-token">' . e($part) . '</bdi>' . e($trail);
                }
            } else {
                $out .= e($part);
            }
        }
        return $out;
    }
}

if (!function_exists('fmt_rich_text')) {
    /**
     * Format multiline task/note text safely: normalizes literal \n sequences,
     * renders numbered steps cleanly, and prevents RTL/LTR line-wrapping corruption.
     */
    function fmt_rich_text(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }
        $normalized = str_replace(["\r\n", "\r", "\\r\\n", "\\n"], "\n", trim($text));
        $lines = explode("\n", $normalized);
        $blocks = [];
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                $blocks[] = '<div class="rich-gap" aria-hidden="true"></div>';
                continue;
            }
            if (preg_match('/^(\d+[\.\)])\s+(.+)$/u', $line, $m) === 1) {
                $blocks[] = '<div class="rich-step"><span class="rich-step-num" dir="ltr">' . e($m[1]) . '</span><span class="rich-step-body">' . fmt_inline_bidi($m[2]) . '</span></div>';
            } else {
                $blocks[] = '<p class="rich-line">' . fmt_inline_bidi($line) . '</p>';
            }
        }
        return implode("\n", $blocks);
    }
}

