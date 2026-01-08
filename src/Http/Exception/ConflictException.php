<?php

namespace LiturgicalCalendar\Api\Http\Exception;

/**
 * Exception thrown when a request conflicts with the current state of the resource.
 *
 * This exception should be used when a request cannot be completed due to a conflict
 * with the current state of the target resource, such as attempting to create a
 * resource that already exists.
 * Maps to HTTP 409 Conflict.
 */
class ConflictException extends ApiException
{
    public function __construct(string $message = 'Conflict', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            409,
            'https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/409',
            'Conflict',
            $previous
        );
    }
}
