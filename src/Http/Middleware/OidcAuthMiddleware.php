<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * OIDC Authentication Middleware for Zitadel tokens.
 *
 * Validates OIDC tokens from Zitadel using JWKS endpoint.
 * Supports both cookie-based and header-based authentication.
 *
 * Request attributes set on successful authentication:
 * - 'oidc_user': Array with user info (sub, email, name, roles)
 * - 'oidc_token': Raw JWT payload for additional claims
 */
class OidcAuthMiddleware implements MiddlewareInterface
{
    private string $issuer;
    private string $clientId;
    private ?CacheInterface $cache;
    private int $cacheTtl;

    /**
     * Cached JWKS key set.
     */
    private static ?CachedKeySet $keySet = null;

    /**
     * Create the OIDC authentication middleware.
     *
     * @param string $issuer Zitadel issuer URL (e.g., http://localhost:8081)
     * @param string $clientId Zitadel client ID for audience validation
     * @param CacheInterface|null $cache Optional PSR-16 cache for JWKS
     * @param int $cacheTtl JWKS cache TTL in seconds (default: 3600)
     */
    public function __construct(
        string $issuer,
        string $clientId,
        ?CacheInterface $cache = null,
        int $cacheTtl = 3600
    ) {
        $this->issuer   = rtrim($issuer, '/');
        $this->clientId = $clientId;
        $this->cache    = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Create middleware from environment variables.
     *
     * Required environment variables:
     * - ZITADEL_ISSUER: Zitadel issuer URL
     * - ZITADEL_CLIENT_ID: Client ID for audience validation
     *
     * @param CacheInterface|null $cache Optional PSR-16 cache
     * @return self
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function fromEnv(?CacheInterface $cache = null): self
    {
        $issuer   = getenv('ZITADEL_ISSUER');
        $clientId = getenv('ZITADEL_CLIENT_ID');

        if ($issuer === false || $clientId === false) {
            throw new \RuntimeException(
                'Missing required environment variables: ZITADEL_ISSUER, ZITADEL_CLIENT_ID'
            );
        }

        return new self($issuer, $clientId, $cache);
    }

    /**
     * Process the request and validate OIDC token.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     * @throws UnauthorizedException If token is missing or invalid
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw new UnauthorizedException('Missing authentication token');
        }

        try {
            $payload = $this->validateToken($token);
        } catch (\Exception $e) {
            throw new UnauthorizedException('Invalid token: ' . $e->getMessage());
        }

        // Validate issuer
        if (!isset($payload->iss) || $payload->iss !== $this->issuer) {
            throw new UnauthorizedException('Invalid token issuer');
        }

        // Validate audience (can be string or array)
        $aud           = $payload->aud ?? null;
        $validAudience = false;
        if (is_string($aud) && $aud === $this->clientId) {
            $validAudience = true;
        } elseif (is_array($aud) && in_array($this->clientId, $aud, true)) {
            $validAudience = true;
        }

        if (!$validAudience) {
            throw new UnauthorizedException('Invalid token audience');
        }

        // Extract user info and roles from token
        $oidcUser = $this->extractUserInfo($payload);

        // Attach to request attributes
        $request = $request->withAttribute('oidc_user', $oidcUser);
        $request = $request->withAttribute('oidc_token', $payload);

        return $handler->handle($request);
    }

    /**
     * Extract token from request (cookie first, then header).
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Token string or null if not found
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // 1. Try HttpOnly cookie first (preferred for security)
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        $token   = CookieHelper::getAccessToken($cookies);

        if ($token !== null) {
            return $token;
        }

        // 2. Fall back to Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if (!empty($authHeader)) {
            if (!str_starts_with(strtolower($authHeader), 'bearer ')) {
                return null;
            }
            return trim(substr($authHeader, 7));
        }

        return null;
    }

    /**
     * Validate token against Zitadel JWKS.
     *
     * @param string $token JWT token string
     * @return object Decoded token payload
     * @throws \Exception If token validation fails
     */
    private function validateToken(string $token): object
    {
        $keySet = $this->getKeySet();

        return JWT::decode($token, $keySet);
    }

    /**
     * Get or create cached JWKS key set.
     *
     * @return CachedKeySet JWKS key set
     */
    private function getKeySet(): CachedKeySet
    {
        if (self::$keySet !== null) {
            return self::$keySet;
        }

        $jwksUri = $this->issuer . '/oauth/v2/keys';

        $httpClient  = new Client();
        $httpFactory = new HttpFactory();

        // Create cached key set
        self::$keySet = new CachedKeySet(
            $jwksUri,
            $httpClient,
            $httpFactory,
            $this->cache,
            $this->cacheTtl,
            true // Rate limit JWKS fetches
        );

        return self::$keySet;
    }

    /**
     * Extract user information from token payload.
     *
     * @param object $payload Token payload
     * @return array User info array
     */
    private function extractUserInfo(object $payload): array
    {
        // Standard OIDC claims
        $user = [
            'sub'                => $payload->sub ?? null,
            'email'              => $payload->email ?? null,
            'email_verified'     => $payload->email_verified ?? false,
            'name'               => $payload->name ?? null,
            'given_name'         => $payload->given_name ?? null,
            'family_name'        => $payload->family_name ?? null,
            'preferred_username' => $payload->preferred_username ?? null,
        ];

        // Zitadel-specific: Extract project roles
        // Zitadel uses the claim: urn:zitadel:iam:org:project:roles
        $rolesClaimKey = 'urn:zitadel:iam:org:project:roles';
        $roles         = [];

        if (isset($payload->{$rolesClaimKey})) {
            // Zitadel returns roles as object with role name as key
            // e.g., {"admin": {"org_id": "123"}, "developer": {"org_id": "123"}}
            $rolesData = (array) $payload->{$rolesClaimKey};
            $roles     = array_keys($rolesData);
        }

        $user['roles'] = $roles;

        // Also check for Zitadel project ID claim
        $projectIdKey = 'urn:zitadel:iam:org:project:id';
        if (isset($payload->{$projectIdKey})) {
            $user['project_id'] = $payload->{$projectIdKey};
        }

        return $user;
    }

    /**
     * Check if a user has a specific role.
     *
     * @param array $oidcUser User array from request attribute
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    public static function hasRole(array $oidcUser, string $role): bool
    {
        return in_array($role, $oidcUser['roles'] ?? [], true);
    }

    /**
     * Check if a user has any of the specified roles.
     *
     * @param array $oidcUser User array from request attribute
     * @param array<string> $roles Roles to check
     * @return bool True if user has any of the roles
     */
    public static function hasAnyRole(array $oidcUser, array $roles): bool
    {
        $userRoles = $oidcUser['roles'] ?? [];
        return !empty(array_intersect($roles, $userRoles));
    }

    /**
     * Check if a user is an admin.
     *
     * @param array $oidcUser User array from request attribute
     * @return bool True if user has admin role
     */
    public static function isAdmin(array $oidcUser): bool
    {
        return self::hasRole($oidcUser, 'admin');
    }

    /**
     * Reset the cached key set (useful for testing).
     */
    public static function resetKeySetCache(): void
    {
        self::$keySet = null;
    }
}
