<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Trait for extracting client IP addresses from HTTP requests.
 *
 * This trait provides a standardized method for determining client IP addresses,
 * accounting for common reverse proxy setups that use X-Forwarded-For or X-Real-IP headers.
 */
trait ClientIpTrait
{
    /**
     * Get the client IP address, checking proxy headers first.
     *
     * Checks X-Forwarded-For and X-Real-IP headers (common in reverse proxy setups)
     * before falling back to REMOTE_ADDR. For X-Forwarded-For, uses the first IP
     * in the chain (the original client).
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @param array<string, mixed> $serverParams Server parameters from the request.
     * @return string The client IP address, or 'unknown' if not determinable.
     */
    private function getClientIp(ServerRequestInterface $request, array $serverParams): string
    {
        // Check X-Forwarded-For header (may contain comma-separated list of IPs)
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor !== '') {
            // Use the first IP in the chain (original client)
            $ips = array_map('trim', explode(',', $forwardedFor));
            if (!empty($ips[0])) {
                return $ips[0];
            }
        }

        // Check X-Real-IP header
        $realIp = $request->getHeaderLine('X-Real-IP');
        if ($realIp !== '') {
            return $realIp;
        }

        // Fall back to REMOTE_ADDR
        return is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : 'unknown';
    }
}
