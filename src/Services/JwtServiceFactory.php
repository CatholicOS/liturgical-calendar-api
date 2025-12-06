<?php

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Environment;

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
     * Common placeholder patterns that indicate an insecure default secret.
     * These patterns are checked case-insensitively.
     */
    private const PLACEHOLDER_PATTERNS = [
        'change-this',
        'change_this',
        'changethis',
        'change-me',
        'change_me',
        'changeme',
        'replace-this',
        'replace_this',
        'replacethis',
        'replace-me',
        'replace_me',
        'replaceme',
        'your-secret',
        'your_secret',
        'yoursecret',
        'my-secret',
        'my_secret',
        'mysecret',
        'secret-key',
        'secret_key',
        'secretkey',
        'example',
        'placeholder',
        'default',
        'insecure',
        'xxxxxxxx',
        'password',
        'test-secret',
        'test_secret',
        'testsecret',
        'dev-secret',
        'dev_secret',
        'devsecret',
        'jwt',
        'dummy',
        'sample',
    ];

    /**
     * Check if a secret appears to be a placeholder value.
     *
     * @param string $secret The secret to check.
     * @return bool True if the secret matches a placeholder pattern.
     */
    private static function isPlaceholderSecret(string $secret): bool
    {
        $lowercaseSecret = strtolower($secret);

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (str_contains($lowercaseSecret, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a JwtService configured from environment variables.
     *
     * Reads these environment variables:
     * - JWT_SECRET (required): signing secret, must be at least 32 characters.
     * - JWT_ALGORITHM: algorithm name (HS256, HS384, or HS512), defaults to 'HS256'.
     * - JWT_EXPIRY: access token lifetime in seconds, defaults to 3600; must be greater than 0.
     * - JWT_REFRESH_EXPIRY: refresh token lifetime in seconds, defaults to 604800; must be greater than 0.
     *
     * In staging/production environments, throws an exception if the JWT_SECRET appears
     * to be a placeholder value (e.g., contains 'change-this', 'your-secret', etc.).
     *
     * @return JwtService The configured JWT service instance.
     * @throws \RuntimeException If JWT_SECRET is missing/empty/too short/placeholder, JWT_ALGORITHM is invalid, or expiry values are not positive integers.
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

        // In production environments, reject placeholder secrets
        if (Environment::isProduction() && self::isPlaceholderSecret($secret)) {
            throw new \RuntimeException(
                'JWT_SECRET appears to be a placeholder value. ' .
                'In staging/production environments, you must use a secure random secret. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $algorithmEnv = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
        $algorithm    = is_string($algorithmEnv) ? $algorithmEnv : 'HS256';
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \RuntimeException('JWT_ALGORITHM must be one of: ' . implode(', ', self::SUPPORTED_ALGORITHMS));
        }

        $expiryEnv = $_ENV['JWT_EXPIRY'] ?? null;
        if ($expiryEnv === null) {
            $expiry = 3600;
        } elseif (is_string($expiryEnv) && !is_numeric($expiryEnv)) {
            throw new \RuntimeException('JWT_EXPIRY must be a numeric value (got: ' . $expiryEnv . ')');
        } elseif (!is_numeric($expiryEnv)) {
            throw new \RuntimeException('JWT_EXPIRY must be a numeric value (got type: ' . get_debug_type($expiryEnv) . ')');
        } else {
            $expiry = (int) $expiryEnv;
            if ($expiry <= 0) {
                throw new \RuntimeException('JWT_EXPIRY must be a positive integer (got: ' . $expiry . ')');
            }
        }

        $refreshExpiryEnv = $_ENV['JWT_REFRESH_EXPIRY'] ?? null;
        if ($refreshExpiryEnv === null) {
            $refreshExpiry = 604800;
        } elseif (is_string($refreshExpiryEnv) && !is_numeric($refreshExpiryEnv)) {
            throw new \RuntimeException('JWT_REFRESH_EXPIRY must be a numeric value (got: ' . $refreshExpiryEnv . ')');
        } elseif (!is_numeric($refreshExpiryEnv)) {
            throw new \RuntimeException('JWT_REFRESH_EXPIRY must be a numeric value (got type: ' . get_debug_type($refreshExpiryEnv) . ')');
        } else {
            $refreshExpiry = (int) $refreshExpiryEnv;
            if ($refreshExpiry <= 0) {
                throw new \RuntimeException('JWT_REFRESH_EXPIRY must be a positive integer (got: ' . $refreshExpiry . ')');
            }
        }

        return new JwtService($secret, $algorithm, $expiry, $refreshExpiry);
    }
}
