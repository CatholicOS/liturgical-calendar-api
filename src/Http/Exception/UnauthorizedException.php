<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnauthorizedException extends ApiException
{
    /**
     * Create an exception representing an HTTP 401 Unauthorized error.
     *
     * Initializes the exception with the HTTP 401 status code, the RFC 9110 reference
     * for 401 Unauthorized, and the standard reason phrase.
     *
     * @param string $message The exception message.
     * @param \Throwable|null $previous Optional previous exception for chaining.
     */
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