<?php

namespace LiturgicalCalendar\Api\Services;

/**
 * Factory for creating JwtService instances from environment variables.
 *
 * This factory centralizes JwtService configuration and prevents configuration
 * drift across different parts of the application (middleware, handlers, etc.).
 */
class JwtServiceFactory
{
    /**
     * Create a JwtService instance from environment variables.
     *
     * Reads JWT configuration from environment variables with sensible defaults:
     * - JWT_SECRET: Required, no default (throws exception if missing)
     * - JWT_ALGORITHM: Defaults to 'HS256'
     * - JWT_EXPIRY: Access token expiry in seconds, defaults to 3600 (1 hour)
     * - JWT_REFRESH_EXPIRY: Refresh token expiry in seconds, defaults to 604800 (7 days)
     *
     * @return JwtService Configured JWT service instance
     * @throws \RuntimeException If JWT_SECRET is not set in environment
     */
    public static function fromEnv(): JwtService
    {
        $secret = $_ENV['JWT_SECRET'] ?? null;
        if ($secret === null || !is_string($secret) || $secret === '') {
            throw new \RuntimeException('JWT_SECRET environment variable is required and must be a non-empty string');
        }

        $algorithmEnv = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
        $algorithm    = is_string($algorithmEnv) ? $algorithmEnv : 'HS256';

        $expiryEnv = $_ENV['JWT_EXPIRY'] ?? '3600';
        $expiry    = is_numeric($expiryEnv) ? (int) $expiryEnv : 3600;

        $refreshExpiryEnv = $_ENV['JWT_REFRESH_EXPIRY'] ?? '604800';
        $refreshExpiry    = is_numeric($refreshExpiryEnv) ? (int) $refreshExpiryEnv : 604800;

        return new JwtService($secret, $algorithm, $expiry, $refreshExpiry);
    }
}
