<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Environment;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTPS Enforcement Middleware
 *
 * This middleware enforces HTTPS connections for sensitive endpoints in
 * production environments. It uses the same HTTPS detection logic as
 * CookieHelper to ensure consistency.
 *
 * Configuration via environment variables:
 * - APP_ENV: Only enforces in 'staging' or 'production' environments
 * - HTTPS_ENFORCEMENT: Set to 'false' to disable (e.g., if TLS terminates at load balancer)
 *
 * @package LiturgicalCalendar\Api\Http\Middleware
 */
class HttpsEnforcementMiddleware implements MiddlewareInterface
{
    /**
     * Check if HTTPS enforcement is enabled.
     *
     * Returns true only if:
     * 1. APP_ENV is 'staging' or 'production'
     * 2. HTTPS_ENFORCEMENT is not explicitly set to 'false'
     *
     * @return bool True if HTTPS should be enforced.
     */
    private static function isEnforcementEnabled(): bool
    {
        // Only enforce in production environments
        if (!Environment::isProduction()) {
            return false;
        }

        // Check if enforcement is explicitly disabled
        $enforcement    = $_ENV['HTTPS_ENFORCEMENT'] ?? 'true';
        $enforcementStr = is_string($enforcement) ? trim($enforcement) : 'true';

        return strtolower($enforcementStr) !== 'false';
    }

    /**
     * Check if the request was made over a secure connection.
     *
     * Derives HTTPS-ness from the PSR-7 request rather than SAPI globals
     * for better decoupling and testability.
     *
     * Checks:
     * 1. URI scheme from the request
     * 2. X-Forwarded-Proto header (for reverse proxy scenarios)
     * 3. Server params (HTTPS, REQUEST_SCHEME, SERVER_PORT)
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return bool True if the request is over HTTPS.
     */
    private static function isSecureRequest(ServerRequestInterface $request): bool
    {
        // Check URI scheme directly
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        // Check X-Forwarded-Proto header (reverse proxy)
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
        if (strtolower($forwardedProto) === 'https') {
            return true;
        }

        // Check server params as fallback
        /** @var array<string, mixed> $serverParams */
        $serverParams = $request->getServerParams();

        if (isset($serverParams['HTTPS']) && $serverParams['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($serverParams['REQUEST_SCHEME']) && $serverParams['REQUEST_SCHEME'] === 'https') {
            return true;
        }

        if (isset($serverParams['SERVER_PORT'])) {
            $port = $serverParams['SERVER_PORT'];
            if (( is_string($port) || is_int($port) ) && (string) $port === '443') {
                return true;
            }
        }

        return false;
    }

    /**
     * Process the request, enforcing HTTPS in production environments.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @param RequestHandlerInterface $handler The next handler.
     * @return ResponseInterface The response.
     * @throws ForbiddenException If HTTPS is required but the request is HTTP.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip enforcement if disabled or not in production
        if (!self::isEnforcementEnabled()) {
            return $handler->handle($request);
        }

        // Check if request is secure using PSR-7 request data
        if (!self::isSecureRequest($request)) {
            throw new ForbiddenException(
                'HTTPS is required for authentication endpoints in production. ' .
                'Please use a secure connection.'
            );
        }

        return $handler->handle($request);
    }
}
