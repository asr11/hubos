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
