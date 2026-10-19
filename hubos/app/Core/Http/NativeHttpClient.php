<?php

declare(strict_types=1);

namespace HubOS\Core\Http;

/** Real HTTP client: ext-curl when available, PHP streams otherwise. GET only. */
final class NativeHttpClient implements HttpClient
{
    private const MAX_BYTES = 2_000_000;

    public function get(string $url, int $timeoutSeconds = 8): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new HttpClientException('Unsupported URL scheme');
        }
        $timeout = max(1, min($timeoutSeconds, 30));
        return function_exists('curl_init') ? $this->viaCurl($url, $timeout) : $this->viaStream($url, $timeout);
    }

    /** @return array{status:int, body:string} */
    private function viaCurl(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpClientException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'HubOS/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        unset($ch); // curl_close() is deprecated on PHP 8.5; the handle is freed here
        if (!is_string($body)) {
            throw new HttpClientException('Network error: ' . $error);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new HttpClientException('Response too large');
        }
        return ['status' => $status, 'body' => $body];
    }

    /** @return array{status:int, body:string} */
    private function viaStream(string $url, int $timeout): array
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'header'          => "Accept: application/json\r\nUser-Agent: HubOS/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        if (!is_string($body)) {
            throw new HttpClientException('Network error');
        }
        $status = 0;
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $headers[0], $m) === 1) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'body' => $body];
    }
}
