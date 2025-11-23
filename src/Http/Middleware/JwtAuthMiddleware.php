<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtService;
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
        if ($jwtService === null) {
            // Create JwtService from environment variables
            $secret = $_ENV['JWT_SECRET'] ?? null;
            if ($secret === null || !is_string($secret)) {
                throw new \RuntimeException('JWT_SECRET environment variable is not set or is not a string');
            }

            $algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
            if (!is_string($algorithm)) {
                throw new \RuntimeException('JWT_ALGORITHM environment variable must be a string');
            }

            $expiryEnv = $_ENV['JWT_EXPIRY'] ?? '3600';
            $expiry    = is_numeric($expiryEnv) ? (int) $expiryEnv : 3600;

            $refreshExpiryEnv = $_ENV['JWT_REFRESH_EXPIRY'] ?? '604800';
            $refreshExpiry    = is_numeric($refreshExpiryEnv) ? (int) $refreshExpiryEnv : 604800;

            $jwtService = new JwtService($secret, $algorithm, $expiry, $refreshExpiry);
        }

        $this->jwtService = $jwtService;
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

        // Extract token
        $token = substr($authHeader, 7); // Remove "Bearer " prefix

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

        // Attach user to request attributes
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('jwt_payload', $payload);

        // Continue with the request
        return $handler->handle($request);
    }
}
