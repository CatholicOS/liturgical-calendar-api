<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Refresh Handler
 *
 * Handles POST /auth/refresh requests
 *
 * This handler:
 * 1. Extracts refresh token from HttpOnly cookie first (preferred, more secure)
 * 2. Falls back to request body for backwards compatibility
 * 3. Generates new access token
 * 4. Sets new access token as HttpOnly cookie
 * 5. Returns tokens in response body for backwards compatibility
 *
 * Accepts:
 * - refresh_token (string) - JWT refresh token (from cookie or body)
 *
 * Returns:
 * - access_token (string) - New JWT access token
 * - expires_in (int) - Token expiry in seconds
 * - token_type (string) - "Bearer"
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class RefreshHandler extends AbstractHandler
{
    use ClientIpTrait;

    private ?JwtService $jwtService = null;
    private Logger $authLogger;

    /**
     * Configure handler defaults for the refresh endpoint.
     *
     * Sets the allowed HTTP method to POST and restricts Accept and Content-Type to JSON.
     * JWT service is lazy-loaded to allow OPTIONS preflight requests to succeed even if
     * JWT configuration is missing.
     */
    public function __construct()
    {
        parent::__construct();

        // Only allow POST method
        $this->allowedRequestMethods = [RequestMethod::POST];

        // Only accept JSON
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];

        // Enable CORS credentials for cookie-based authentication
        $this->allowCredentials = true;

        // Initialize auth logger
        $this->authLogger = LoggerFactory::create('auth', null, 30, false, true, false);
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
     * Process a refresh-token request and return a JSON response containing a new access token.
     *
     * Validates the request (method, headers, body), extracts the `refresh_token`, exchanges it for
     * a new access token, and returns a response with `access_token`, `expires_in`, and `token_type`.
     *
     * @param ServerRequestInterface $request The incoming HTTP request for the refresh operation.
     * @return ResponseInterface The HTTP response containing the JSON payload with `access_token`, `expires_in`, and `token_type`.
     * @throws ValidationException If the `refresh_token` is missing or not a non-empty string.
     * @throws UnauthorizedException If the provided refresh token is invalid or expired.
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

        // Get client IP for logging (check proxy headers first, then fall back to REMOTE_ADDR)
        /** @var array<string, mixed> $serverParams */
        $serverParams = $request->getServerParams();
        $clientIp     = $this->getClientIp($request, $serverParams);

        // 1. Try to get refresh token from HttpOnly cookie first (preferred, more secure)
        /** @var array<string, string> $cookies */
        $cookies      = $request->getCookieParams();
        $refreshToken = CookieHelper::getRefreshToken($cookies);

        // 2. Fall back to request body for backwards compatibility
        if ($refreshToken === null) {
            // Parse request body (required=false since cookie may have provided token)
            $parsedBodyParams = $this->parseBodyParams($request, false);
            $refreshToken     = $parsedBodyParams['refresh_token'] ?? null;
        }

        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new ValidationException('Refresh token is required and must be a string');
        }

        // Refresh the access token (lazy-load JWT service here, after OPTIONS check)
        $jwtService = $this->getJwtService();
        $newToken   = $jwtService->refresh($refreshToken);

        if ($newToken === null) {
            // Log failed refresh attempt
            $this->authLogger->warning('Token refresh failed', [
                'client_ip' => $clientIp,
                'reason'    => 'Invalid or expired refresh token'
            ]);
            throw new UnauthorizedException('Invalid or expired refresh token');
        }

        // Log successful refresh
        $username = $jwtService->extractUsername($refreshToken) ?? 'unknown';
        $this->authLogger->info('Token refresh successful', [
            'username'  => $username,
            'client_ip' => $clientIp
        ]);

        // Set HttpOnly cookie for secure token storage
        $response = CookieHelper::setAccessTokenCookie($response, $newToken, $jwtService->getExpiry());

        // TODO: Consider implementing refresh token rotation for enhanced security.
        // Rotating the refresh token on each use limits the window of exposure if compromised.
        // This would involve generating a new refresh token and updating its cookie here.

        // Prepare response data (token still included for backwards compatibility)
        $responseData = [
            'access_token' => $newToken,
            'expires_in'   => $jwtService->getExpiry(),
            'token_type'   => 'Bearer'
        ];

        // Add Cache-Control header to prevent intermediaries from caching tokens
        $response = $response->withHeader('Cache-Control', 'no-store');

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
