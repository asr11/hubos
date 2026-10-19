<?php

declare(strict_types=1);

namespace HubOS\Core;

/**
 * Minimal i18n: t('key', ['n' => 3]) looks up lang/<locale>.php, falls back
 * to the key itself. Placeholders use {name}.
 */
final class Lang
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];
    private static string $locale = 'he';

    public static function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
            throw new \InvalidArgumentException('Invalid locale');
        }
        self::$locale = $locale;
    }

    /** @param array<string, scalar|null> $vars */
    public static function t(string $key, array $vars = []): string
    {
        $text = self::table(self::$locale)[$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    public static function has(string $key): bool
    {
        return isset(self::table(self::$locale)[$key]);
    }

    /** @return array<string, string> */
    private static function table(string $locale): array
    {
        if (!isset(self::$cache[$locale])) {
            $file = HUBOS_ROOT . '/lang/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            self::$cache[$locale] = is_array($data) ? $data : [];
        }
        return self::$cache[$locale];
    }
}
