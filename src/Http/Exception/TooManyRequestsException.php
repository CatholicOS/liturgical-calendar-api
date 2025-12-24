<?php

namespace LiturgicalCalendar\Api\Http\Exception;

/**
 * Exception for HTTP 429 Too Many Requests responses
 *
 * Used when rate limiting is triggered to inform clients they have
 * exceeded the allowed number of requests within the time window.
 *
 * @package LiturgicalCalendar\Api\Http\Exception
 */
class TooManyRequestsException extends ApiException
{
    private int $retryAfter;

    /**
     * Create a new TooManyRequestsException
     *
     * @param string $message Error message describing the rate limit
     * @param int $retryAfter Number of seconds until the client can retry
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Too many requests. Please try again later.',
        int $retryAfter = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            429,
            'https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429',
            'Too Many Requests',
            $previous
        );

        $this->retryAfter = max(0, $retryAfter);
    }

    /**
     * Get the number of seconds until the client can retry
     *
     * @return int Seconds until retry is allowed
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Convert the exception to an array suitable for JSON problem details.
     *
     * @param bool $includeDebug Whether to include file, line, and stack trace.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDebug = false): array
    {
        $data = parent::toArray($includeDebug);

        if ($this->retryAfter > 0) {
            $data['retryAfter'] = $this->retryAfter;
        }

        return $data;
    }
}
