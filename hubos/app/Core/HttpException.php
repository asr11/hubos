<?php

declare(strict_types=1);

namespace HubOS\Core;

use RuntimeException;

/** Exception that maps to an HTTP status (403, 404, ...). Message is user-safe Hebrew. */
class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
