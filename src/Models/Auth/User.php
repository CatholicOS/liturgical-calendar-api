<?php

namespace LiturgicalCalendar\Api\Models\Auth;

/**
 * User Model for Authentication
 *
 * This is a simplified user model for Phase 0 of JWT authentication.
 * It authenticates against credentials stored in environment variables.
 *
 * Future enhancements:
 * - Database-backed user storage
 * - Multiple user support
 * - Role-based access control
 * - User management UI
 *
 * @package LiturgicalCalendar\Api\Models\Auth
 */
class User
{
    public readonly string $username;
    public readonly string $passwordHash;
    /**
     * @var string[]
     */
    public readonly array $roles;

    /**
     * Cached development password hash to avoid re-hashing on every authentication
     */
    private static ?string $devPasswordHash = null;

    /**
     * Create a User model instance.
     *
     * @param string   $username     The user's username.
     * @param string   $passwordHash The stored password hash (as produced by `password_hash()`).
     * @param string[] $roles        List of user roles (defaults to `['admin']`).
     */
    public function __construct(
        string $username,
        string $passwordHash,
        array $roles = ['admin']
    ) {
        $this->username     = $username;
        $this->passwordHash = $passwordHash;
        $this->roles        = $roles;
    }

    /**
     * Authenticate the configured admin user using credentials sourced from environment variables.
     *
     * Validates that APP_ENV is one of: development, test, staging, production. Uses ADMIN_PASSWORD_HASH when provided;
     * in development/test, a cached default hash for the literal password "password" is permitted. Returns null for
     * non-matching username or password.
     *
     * @param string $username The username to authenticate; compared against the `ADMIN_USERNAME` environment variable (default: "admin").
     * @param string $password The plain-text password to verify against the configured or generated admin password hash.
     * @return self|null A User instance representing the authenticated admin, or `null` if authentication fails.
     * @throws \RuntimeException If APP_ENV is missing/invalid, if ADMIN_PASSWORD_HASH is required but missing, or if a development password hash cannot be generated.
     */
    public static function authenticate(string $username, string $password): ?self
    {
        // Get admin credentials from environment
        /** @var string $adminUsername */
        $adminUsername     = $_ENV['ADMIN_USERNAME'] ?? 'admin';
        $adminPasswordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;

        // Check if credentials match (use constant-time comparison to prevent timing attacks)
        if (!hash_equals($adminUsername, $username)) {
            return null;
        }

        // Fail-closed approach: Validate APP_ENV is set and is a known value
        $appEnv            = $_ENV['APP_ENV'] ?? null;
        $knownEnvironments = ['development', 'test', 'staging', 'production'];

        if ($appEnv === null || !in_array($appEnv, $knownEnvironments, true)) {
            $appEnvDescription = $appEnv === null ? 'not set' : var_export($appEnv, true);
            error_log(sprintf(
                'Authentication failed: APP_ENV is %s (must be one of: %s)',
                $appEnvDescription,
                implode(', ', $knownEnvironments)
            ));
            throw new \RuntimeException(
                'APP_ENV must be set to a valid environment: ' . implode(', ', $knownEnvironments)
            );
        }

        // Validate that password hash is configured
        if ($adminPasswordHash === null || !is_string($adminPasswordHash)) {
            // Only allow default password in development and test environments
            if ($appEnv === 'development' || $appEnv === 'test') {
                // Cache the development password hash to avoid re-hashing on every authentication
                // Argon2id is intentionally slow, so caching significantly improves performance
                if (self::$devPasswordHash === null) {
                    $hash = password_hash('password', PASSWORD_ARGON2ID);
                    // Defensive check: password_hash() can theoretically return false
                    // @phpstan-ignore identical.alwaysFalse
                    if ($hash === false) {
                        error_log('Authentication failed: password_hash() returned false');
                        throw new \RuntimeException('Failed to generate password hash');
                    }
                    self::$devPasswordHash = $hash;
                }
                $adminPasswordHash = self::$devPasswordHash;
            } else {
                // Production and staging MUST have ADMIN_PASSWORD_HASH configured
                error_log(sprintf(
                    'Authentication failed: ADMIN_PASSWORD_HASH not set in %s environment',
                    $appEnv
                ));
                throw new \RuntimeException(
                    "ADMIN_PASSWORD_HASH environment variable is required in {$appEnv} environment"
                );
            }
        }

        // Verify password
        if (!password_verify($password, $adminPasswordHash)) {
            return null;
        }

        // Return authenticated user
        return new self($username, $adminPasswordHash, ['admin']);
    }

    /**
     * Determine whether the user has a given role.
     *
     * @param string $role Role name to check.
     * @return bool `true` if the user has the role, `false` otherwise.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * User data formatted for inclusion in JWT claims.
     *
     * @return array{username: string, roles: string[]} Associative array with keys `username` and `roles`.
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'roles'    => $this->roles
        ];
    }

    /**
         * Instantiate a User from a JWT payload after validating required claims.
         *
         * Expects the payload to contain a string `sub` claim used as the username.
         * Optionally accepts a `roles` claim which must be an array of strings; if absent the default `['admin']` is used.
         * Returns a User with an empty password hash on successful validation.
         *
         * @param object $payload JWT payload; must contain `sub` as string and, if present, `roles` as string[]
         * @return self|null A User instance with an empty password hash if payload is valid, `null` otherwise
         */
    public static function fromJwtPayload(object $payload): ?self
    {
        if (!isset($payload->sub) || !is_string($payload->sub)) {
            return null;
        }

        $username         = $payload->sub;
        $rolesFromPayload = $payload->roles ?? ['admin'];

        // Validate roles is an array
        if (!is_array($rolesFromPayload)) {
            return null;
        }

        // Ensure all roles are strings
        /** @var string[] $roles */
        $roles = array_filter($rolesFromPayload, 'is_string');
        if (count($roles) !== count($rolesFromPayload)) {
            // Some roles were not strings, invalid payload
            return null;
        }

        // For now, we don't have the password hash from JWT
        // This is sufficient for authorization checks
        return new self($username, '', $roles);
    }
}
