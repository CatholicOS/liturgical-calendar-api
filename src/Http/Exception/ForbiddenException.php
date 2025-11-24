<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ForbiddenException extends ApiException
{
    /**
     * Create a ForbiddenException representing the HTTP 403 Forbidden error.
     *
     * Initializes the exception with the 403 status code, the RFC 9110 reference for 403, and the standard reason phrase.
     *
     * @param string $message Exception message; defaults to "Forbidden".
     * @param \Throwable|null $previous Previous throwable for exception chaining, or null.
     */
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::FORBIDDEN->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-403-forbidden',
            StatusCode::FORBIDDEN->reason(),
            $previous
        );
    }
}