<?php

declare(strict_types=1);

namespace HubOS\Core;

/** Immutable-ish HTTP response returned by every handler. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Internal redirects only (prevents open redirect). */
    public static function redirect(string $to, int $status = 303): self
    {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }
        return new self('', $status, ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        echo $this->body;
    }
}
