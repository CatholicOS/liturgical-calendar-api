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
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Nyholm\Psr7\Stream;
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
 *
 * Returns:
 * - token (string) - JWT access token
 * - refresh_token (string) - JWT refresh token
 * - expires_in (int) - Token expiry in seconds
 * - token_type (string) - "Bearer"
 *
 * @package LiturgicalCalendar\Api\Handlers\Auth
 */
final class LoginHandler extends AbstractHandler
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
     * Handle the login request
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

        // Extract username and password
        $username = $parsedBodyParams['username'] ?? null;
        $password = $parsedBodyParams['password'] ?? null;

        if (!is_string($username) || !is_string($password) || empty($username) || empty($password)) {
            throw new ValidationException('Username and password are required and must be strings');
        }

        // Authenticate user
        $user = User::authenticate($username, $password);

        if ($user === null) {
            throw new UnauthorizedException('Invalid username or password');
        }

        // Generate tokens
        $token        = $this->jwtService->generate($user->username, ['roles' => $user->roles]);
        $refreshToken = $this->jwtService->generateRefreshToken($user->username);

        // Prepare response data
        $responseData = [
            'access_token'  => $token,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->jwtService->getExpiry(),
            'token_type'    => 'Bearer'
        ];

        // Encode response (encodeResponseBody sets status to 200 OK by default)
        return $this->encodeResponseBody($response, $responseData);
    }
}
