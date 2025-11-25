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
    private const SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512'];

    /**
     * Create a JwtService configured from environment variables.
     *
     * Reads these environment variables:
     * - JWT_SECRET (required): signing secret, must be at least 32 characters.
     * - JWT_ALGORITHM: algorithm name (HS256, HS384, or HS512), defaults to 'HS256'.
     * - JWT_EXPIRY: access token lifetime in seconds, defaults to 3600; must be greater than 0.
     * - JWT_REFRESH_EXPIRY: refresh token lifetime in seconds, defaults to 604800; must be greater than 0.
     *
     * @return JwtService The configured JWT service instance.
     * @throws \RuntimeException If JWT_SECRET is missing/empty/too short, JWT_ALGORITHM is invalid, or expiry values are not positive integers.
     */
    public static function fromEnv(): JwtService
    {
        $secret = $_ENV['JWT_SECRET'] ?? null;
        if ($secret === null || !is_string($secret) || $secret === '') {
            throw new \RuntimeException('JWT_SECRET environment variable is required and must be a non-empty string');
        }
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long');
        }

        $algorithmEnv = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
        $algorithm    = is_string($algorithmEnv) ? $algorithmEnv : 'HS256';
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \RuntimeException('JWT_ALGORITHM must be one of: ' . implode(', ', self::SUPPORTED_ALGORITHMS));
        }

        $expiryEnv = $_ENV['JWT_EXPIRY'] ?? '3600';
        $expiry    = is_numeric($expiryEnv) ? (int) $expiryEnv : 3600;
        if ($expiry <= 0) {
            throw new \RuntimeException('JWT_EXPIRY must be a positive integer (got: ' . $expiry . ')');
        }

        $refreshExpiryEnv = $_ENV['JWT_REFRESH_EXPIRY'] ?? '604800';
        $refreshExpiry    = is_numeric($refreshExpiryEnv) ? (int) $refreshExpiryEnv : 604800;
        if ($refreshExpiry <= 0) {
            throw new \RuntimeException('JWT_REFRESH_EXPIRY must be a positive integer (got: ' . $refreshExpiry . ')');
        }

        return new JwtService($secret, $algorithm, $expiry, $refreshExpiry);
    }
}
