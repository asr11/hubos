<?php

declare(strict_types=1);

namespace HubOS\Core;

/** Synchronizer-token CSRF protection, stored in the session. */
final class Csrf
{
    public const FIELD = '_csrf';
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY]) || strlen($_SESSION[self::KEY]) !== 64) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function verify(mixed $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public static function fromRequest(): ?string
    {
        $token = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return is_string($token) ? $token : null;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . View::e(self::token()) . '">';
    }

    public static function rotate(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
