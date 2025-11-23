<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::UNAUTHORIZED->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-401-unauthorized',
            StatusCode::UNAUTHORIZED->reason(),
            $previous
        );
    }
}
