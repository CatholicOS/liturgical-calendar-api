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
     * Constructor
     *
     * @param string   $username     Username
     * @param string   $passwordHash Password hash (from password_hash())
     * @param string[] $roles        User roles (default: ['admin'])
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
     * Authenticate a user with username and password
     *
     * @param string $username Username
     * @param string $password Plain-text password
     * @return self|null       User instance if authentication succeeds, null otherwise
     */
    public static function authenticate(string $username, string $password): ?self
    {
        // Get admin credentials from environment
        $adminUsername     = $_ENV['ADMIN_USERNAME'] ?? 'admin';
        $adminPasswordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;

        // Check if credentials match
        if ($username !== $adminUsername) {
            return null;
        }

        // Validate that password hash is a string
        if ($adminPasswordHash === null || !is_string($adminPasswordHash)) {
            // Default password for development: 'password'
            // This is intentionally weak for development convenience
            // In production, ADMIN_PASSWORD_HASH MUST be set in .env
            if ($_ENV['APP_ENV'] === 'production') {
                throw new \RuntimeException('ADMIN_PASSWORD_HASH must be set in production environment');
            }
            $adminPasswordHash = password_hash('password', PASSWORD_ARGON2ID);
            // password_hash with PASSWORD_ARGON2ID always succeeds
        }

        // Verify password
        if (!password_verify($password, $adminPasswordHash)) {
            return null;
        }

        // Return authenticated user
        return new self($username, $adminPasswordHash, ['admin']);
    }

    /**
     * Check if user has a specific role
     *
     * @param string $role Role to check
     * @return bool        True if user has the role, false otherwise
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Get user information as an array (for JWT claims)
     *
     * @return array{username: string, roles: string[]} User information
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'roles'    => $this->roles
        ];
    }

    /**
     * Create a user instance from JWT payload
     *
     * @param object $payload JWT payload
     * @return self|null      User instance, or null if payload is invalid
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
