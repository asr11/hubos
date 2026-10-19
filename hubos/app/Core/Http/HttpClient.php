<?php

declare(strict_types=1);

namespace HubOS\Core\Http;

/** Minimal outbound HTTP abstraction (mocked in tests — tests never hit the network). */
interface HttpClient
{
    /**
     * @return array{status:int, body:string}
     * @throws HttpClientException on network failure / timeout
     */
    public function get(string $url, int $timeoutSeconds = 8): array;
}
