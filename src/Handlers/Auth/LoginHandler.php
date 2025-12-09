<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\TooManyRequestsException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Api\Services\RateLimiter;
use LiturgicalCalendar\Api\Services\RateLimiterFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Login Handler
 *
 * Handles POST /auth/login requests
 *
 * Accepts:
 * - username (string)
 * - password (string)
 * - remember_me (boolean, optional) - When true, refresh token cookie persists beyond browser session
 *
 * Returns:
 * - access_token (string) - JWT access token
 * - refresh_token (string) - JWT refresh token
 * - expires_in (int) - Token expiry in seconds
 * - token_type (string) - "Bearer"
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class LoginHandler extends AbstractHandler
{
    use ClientIpTrait;

    private ?JwtService $jwtService   = null;
    private ?RateLimiter $rateLimiter = null;
    private Logger $authLogger;

    /**
     * Initialize the login handler with allowed methods, accepted content types.
     *
     * Sets the handler to accept only POST requests with JSON accept and content-type headers.
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
     * Get the rate limiter instance, creating it if needed (lazy loading).
     */
    private function getRateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = RateLimiterFactory::fromEnv();
        }
        return $this->rateLimiter;
    }

    /**
     * Process a login request and return an authentication response.
     *
     * Authenticates username and password from the JSON request body and returns
     * a JSON response containing an access token, a refresh token, the token
     * expiry (seconds), and the token type.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return ResponseInterface Response with a JSON body containing `access_token`, `refresh_token`, `expires_in`, and `token_type`.
     * @throws TooManyRequestsException If rate limit is exceeded for the client IP.
     * @throws UnauthorizedException If authentication fails for the provided credentials.
     * @throws ValidationException If the request body is missing or username/password are invalid.
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

        // Get client IP for rate limiting and logging (check proxy headers first, then fall back to REMOTE_ADDR)
        /** @var array<string, mixed> $serverParams */
        $serverParams = $request->getServerParams();
        $clientIp     = $this->getClientIp($request, $serverParams);

        // Check rate limit before processing login
        $rateLimiter = $this->getRateLimiter();
        if ($rateLimiter->isRateLimited($clientIp)) {
            $retryAfter = $rateLimiter->getRetryAfter($clientIp);
            $this->authLogger->warning('Login rate limited', [
                'client_ip'   => $clientIp,
                'retry_after' => $retryAfter
            ]);
            throw new TooManyRequestsException(
                'Too many login attempts. Please try again later.',
                $retryAfter
            );
        }

        // Parse request body (required=true handles Content-Type and empty body validation)
        $parsedBodyParams = $this->parseBodyParams($request, true);

        // Extract username, password, and remember_me option
        $username   = $parsedBodyParams['username'] ?? null;
        $password   = $parsedBodyParams['password'] ?? null;
        $rememberMe = $parsedBodyParams['remember_me'] ?? false;

        if (!is_string($username) || !is_string($password) || trim($username) === '' || trim($password) === '') {
            throw new ValidationException('Username and password are required and must be non-empty strings');
        }

        // Ensure remember_me is a boolean
        $rememberMe = filter_var($rememberMe, FILTER_VALIDATE_BOOLEAN);

        // Authenticate user
        $user = User::authenticate($username, $password);

        if ($user === null) {
            // Record failed attempt for rate limiting
            $rateLimiter->recordFailedAttempt($clientIp);
            $remaining = $rateLimiter->getRemainingAttempts($clientIp);

            // Log failed login attempt
            $this->authLogger->warning('Login failed', [
                'username'           => $username,
                'client_ip'          => $clientIp,
                'reason'             => 'Invalid credentials',
                'remaining_attempts' => $remaining
            ]);
            throw new UnauthorizedException('Invalid username or password');
        }

        // Clear rate limit on successful login (good UX, prevents legitimate users from being locked out)
        $rateLimiter->clearAttempts($clientIp);

        // Generate tokens (lazy-load JWT service here, after OPTIONS check)
        $jwtService   = $this->getJwtService();
        $token        = $jwtService->generate($user->username, ['roles' => $user->roles]);
        $refreshToken = $jwtService->generateRefreshToken($user->username);

        // Log successful login
        $this->authLogger->info('Login successful', [
            'username'  => $user->username,
            'client_ip' => $clientIp
        ]);

        // Set HttpOnly cookies for secure token storage
        // Access token: persistent cookie with short TTL (e.g., 15-60 min), refreshed automatically
        // Refresh token: session cookie (deleted on browser close) unless remember_me=true
        $response = CookieHelper::setAccessTokenCookie($response, $token, $jwtService->getExpiry());
        $response = CookieHelper::setRefreshTokenCookie($response, $refreshToken, $jwtService->getRefreshExpiry(), $rememberMe);

        // Prepare response data (tokens still included for backwards compatibility)
        $responseData = [
            'access_token'  => $token,
            'refresh_token' => $refreshToken,
            'expires_in'    => $jwtService->getExpiry(),
            'token_type'    => 'Bearer'
        ];

        // Add Cache-Control header to prevent intermediaries from caching tokens
        $response = $response->withHeader('Cache-Control', 'no-store');

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
