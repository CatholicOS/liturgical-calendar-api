<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Logout Handler
 *
 * Handles POST /auth/logout requests
 *
 * This is a stateless logout endpoint. Since JWT tokens are stateless,
 * the actual token invalidation happens client-side by deleting the stored tokens.
 * This endpoint provides a consistent API for logout operations and can be
 * extended in the future to support token blacklisting if needed.
 *
 * Returns:
 * - message (string) - Success message
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class LogoutHandler extends AbstractHandler
{
    private ?JwtService $jwtService = null;
    private Logger $authLogger;

    /**
     * Initialize the logout handler with allowed methods and accepted content types.
     *
     * Sets the handler to accept only POST requests with JSON accept and content-type headers.
     */
    public function __construct()
    {
        parent::__construct();

        // Only allow POST method
        $this->allowedRequestMethods = [RequestMethod::POST];

        // Only accept JSON
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];

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

    /**
     * Process a logout request and return a success response.
     *
     * Since JWTs are stateless, this endpoint simply returns a success message.
     * The client is responsible for deleting the tokens from storage.
     *
     * @param ServerRequestInterface $request The incoming HTTP request.
     * @return ResponseInterface Response with a JSON body containing a success message.
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

        // Try to extract username from Authorization header for logging
        $username   = 'unknown';
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            try {
                $jwtService = $this->getJwtService();
                $username   = $jwtService->extractUsername($token) ?? 'unknown';
            } catch (\Throwable $e) {
                // Any JWT/config issue: keep logout successful, log at debug level
                $this->authLogger->debug('Failed to extract username from JWT during logout', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Log the logout event
        $this->authLogger->info('Logout', [
            'username'  => $username,
            'client_ip' => $clientIp
        ]);

        // Prepare response data
        $responseData = [
            'message' => 'Logged out successfully'
        ];

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
