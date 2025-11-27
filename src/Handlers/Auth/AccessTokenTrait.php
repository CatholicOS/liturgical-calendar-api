<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Http\CookieHelper;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trait for extracting JWT access tokens from HTTP requests.
 *
 * This trait provides a standardized method for extracting access tokens,
 * checking HttpOnly cookies first (more secure) and falling back to the
 * Authorization header for backwards compatibility.
 */
trait AccessTokenTrait
{
    /**
     * Extract access token from request (cookie first, then Authorization header).
     *
     * Checks HttpOnly cookie first (preferred, more secure), then falls back to
     * the Authorization header for backwards compatibility.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return string|null The access token, or null if not found.
     */
    private function extractAccessToken(ServerRequestInterface $request): ?string
    {
        // 1. Try to get token from HttpOnly cookie first (preferred, more secure)
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        $token   = CookieHelper::getAccessToken($cookies);

        // 2. Fall back to Authorization header for backwards compatibility
        if ($token === null) {
            $authHeader = $request->getHeaderLine('Authorization');

            if (!empty($authHeader) && str_starts_with(strtolower($authHeader), 'bearer ')) {
                $token = trim(substr($authHeader, 7));
            }
        }

        // Return null for empty strings
        if ($token === '') {
            return null;
        }

        return $token;
    }
}
