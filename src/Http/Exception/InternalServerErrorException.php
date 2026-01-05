<?php

namespace LiturgicalCalendar\Api\Http\Exception;

/**
 * Exception thrown when an internal server error occurs.
 *
 * This exception should be used for server-side failures such as
 * file system errors, database errors, or other infrastructure issues.
 * Maps to HTTP 500 Internal Server Error.
 */
class InternalServerErrorException extends ApiException
{
    public function __construct(string $message = 'Internal Server Error', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            500,
            'https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/500',
            'Internal Server Error',
            $previous
        );
    }
}
