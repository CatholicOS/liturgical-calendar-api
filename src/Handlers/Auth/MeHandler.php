<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Me Handler
 *
 * Handles GET /auth/me requests
 *
 * This handler:
 * 1. Extracts JWT token from HttpOnly cookie first (preferred, more secure)
 * 2. Falls back to Authorization header for backwards compatibility
 * 3. Verifies the token
 * 4. Returns user information from the token payload
 *
 * This endpoint is essential for cookie-based authentication where the frontend
 * cannot read the HttpOnly cookie to determine authentication state.
 *
 * Returns:
 * - authenticated (bool) - Whether the user is authenticated
 * - username (string) - Username from JWT payload
 * - roles (array) - User roles from JWT payload
 * - exp (int) - Token expiration timestamp
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class MeHandler extends AbstractHandler
{
    private ?JwtService $jwtService = null;

    /**
     * Initialize the me handler with allowed methods and accepted content types.
     *
     * Sets the handler to accept only GET requests with JSON accept header.
     * JWT service is lazy-loaded to allow OPTIONS preflight requests to succeed even if
     * JWT configuration is missing.
     */
    public function __construct()
    {
        parent::__construct();

        // Only allow GET method
        $this->allowedRequestMethods = [RequestMethod::GET];

        // Only accept JSON
        $this->allowedAcceptHeaders = [AcceptHeader::JSON];

        // Enable CORS credentials for cookie-based authentication
        $this->allowCredentials = true;
    }

    /**
     * Get the JWT service instance, creating it if needed (lazy loading).
     *
     * @throws \RuntimeException If JWT configuration is missing or invalid.
     */
    private function getJwtService(): JwtService
    {
        if ($this->jwtService === null) {
            $this->jwtService = JwtServiceFactory::fromEnv();
        }
        return $this->jwtService;
    }

    /**
     * Process a /auth/me request and return user information.
     *
     * Extracts and verifies the JWT token from cookie or Authorization header,
     * then returns the user information from the token payload.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return ResponseInterface Response with JSON body containing user information.
     * @throws UnauthorizedException If no valid token is found.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Initialize response
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // Handle OPTIONS for CORS preflight
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // Validate request method
        $this->validateRequestMethod($request);

        // Validate Accept header
        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // 1. Try to get token from HttpOnly cookie first (preferred, more secure)
        $token = null;

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

        // No token found
        if ($token === null || $token === '') {
            throw new UnauthorizedException('Not authenticated');
        }

        // Verify token
        $jwtService = $this->getJwtService();
        $payload    = $jwtService->verify($token);

        if ($payload === null) {
            throw new UnauthorizedException('Invalid or expired token');
        }

        // Prepare response data from JWT payload
        $responseData = [
            'authenticated' => true,
            'username'      => $payload->sub ?? $payload->username ?? null,
            'roles'         => $payload->roles ?? [],
            'exp'           => $payload->exp ?? null
        ];

        // Add Cache-Control header to prevent caching of user state
        $response = $response->withHeader('Cache-Control', 'no-store');

        // Encode response
        return $this->encodeResponseBody($response, $responseData);
    }
}
