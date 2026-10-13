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
