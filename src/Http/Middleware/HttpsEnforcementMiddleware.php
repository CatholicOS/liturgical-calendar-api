<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\CookieHelper;
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
        // Check environment
        $appEnv       = $_ENV['APP_ENV'] ?? 'development';
        $appEnvStr    = is_string($appEnv) ? trim($appEnv) : 'development';
        $isProduction = in_array(strtolower($appEnvStr), ['staging', 'production'], true);

        if (!$isProduction) {
            return false;
        }

        // Check if enforcement is explicitly disabled
        $enforcement    = $_ENV['HTTPS_ENFORCEMENT'] ?? 'true';
        $enforcementStr = is_string($enforcement) ? trim($enforcement) : 'true';

        return strtolower($enforcementStr) !== 'false';
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

        // Check if request is secure using CookieHelper's detection logic
        if (!CookieHelper::isSecure()) {
            throw new ForbiddenException(
                'HTTPS is required for authentication endpoints in production. ' .
                'Please use a secure connection.'
            );
        }

        return $handler->handle($request);
    }
}
