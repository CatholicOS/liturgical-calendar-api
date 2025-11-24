<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JWT Authentication Middleware
 *
 * This middleware:
 * 1. Extracts JWT token from Authorization header
 * 2. Verifies the token using JwtService
 * 3. Attaches authenticated user to request attributes
 * 4. Throws UnauthorizedException if token is missing or invalid
 *
 * @package LiturgicalCalendar\Api\Http\Middleware
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private JwtService $jwtService;

    /**
     * Constructor
     *
     * @param JwtService|null $jwtService Optional JWT service (for dependency injection)
     */
    public function __construct(?JwtService $jwtService = null)
    {
        $this->jwtService = $jwtService ?? JwtServiceFactory::fromEnv();
    }

    /**
     * Process the request and authenticate using JWT
     *
     * @param ServerRequestInterface  $request Request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface       Response
     * @throws UnauthorizedException   If authentication fails
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Extract token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            throw new UnauthorizedException('Missing Authorization header');
        }

        // Check for Bearer token format
        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new UnauthorizedException('Invalid Authorization header format. Expected: Bearer <token>');
        }

        // Extract token and trim whitespace
        $token = trim(substr($authHeader, 7)); // Remove "Bearer " prefix and trim

        if (empty($token)) {
            throw new UnauthorizedException('Missing JWT token');
        }

        // Verify token
        $payload = $this->jwtService->verify($token);

        if ($payload === null) {
            throw new UnauthorizedException('Invalid or expired JWT token');
        }

        // Create user from payload
        $user = User::fromJwtPayload($payload);

        if ($user === null) {
            throw new UnauthorizedException('Invalid user in JWT token');
        }

        // Attach both user and payload to request attributes
        // - 'user': Type-safe User object for authentication/authorization checks
        // - 'jwt_payload': Raw JWT payload for accessing custom claims beyond User properties
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('jwt_payload', $payload);

        // Continue with the request
        return $handler->handle($request);
    }
}
