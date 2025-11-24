<?php

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Refresh Handler
 *
 * Handles POST /auth/refresh requests
 *
 * Accepts:
 * - refresh_token (string) - JWT refresh token
 *
 * Returns:
 * - token (string) - New JWT access token
 * - expires_in (int) - Token expiry in seconds
 * - token_type (string) - "Bearer"
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class RefreshHandler extends AbstractHandler
{
    private JwtService $jwtService;

    public function __construct()
    {
        parent::__construct();

        // Only allow POST method
        $this->allowedRequestMethods = [RequestMethod::POST];

        // Only accept JSON
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];

        // Initialize JWT service from environment
        $this->jwtService = JwtServiceFactory::fromEnv();
    }

    /**
     * Handle the token refresh request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws UnauthorizedException
     * @throws ValidationException
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

        // Parse request body (required=true handles Content-Type and empty body validation)
        $parsedBodyParams = $this->parseBodyParams($request, true);

        // Extract refresh token
        $refreshToken = $parsedBodyParams['refresh_token'] ?? null;

        if (!is_string($refreshToken) || empty($refreshToken)) {
            throw new ValidationException('Refresh token is required and must be a string');
        }

        // Refresh the access token
        $newToken = $this->jwtService->refresh($refreshToken);

        if ($newToken === null) {
            throw new UnauthorizedException('Invalid or expired refresh token');
        }

        // Prepare response data
        $responseData = [
            'access_token' => $newToken,
            'expires_in'   => $this->jwtService->getExpiry(),
            'token_type'   => 'Bearer'
        ];

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
