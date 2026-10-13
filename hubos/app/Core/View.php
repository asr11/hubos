<?php

declare(strict_types=1);

namespace HubOS\Core;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

/** Plain-PHP templates with mandatory escaping helper e(). */
final class View
{
    public function __construct(private string $dir)
    {
    }

    public static function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            $value = '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $template) !== 1) {
            throw new InvalidArgumentException('Invalid template name');
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            include $__file;
        };

        ob_start();
        try {
            $render($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
