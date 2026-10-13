<?php

declare(strict_types=1);

namespace HubOS\Core;

/** Input validation failure carrying field => message (Hebrew). */
final class ValidationException extends HttpException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(422, 'נתונים לא תקינים');
    }
}
