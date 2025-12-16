<?php

namespace LiturgicalCalendar\Api\Services;

/**
 * Factory for creating RateLimiter instances from environment variables
 *
 * @package LiturgicalCalendar\Api\Services
 */
class RateLimiterFactory
{
    /**
     * Create a RateLimiter configured from environment variables
     *
     * Reads these environment variables:
     * - RATE_LIMIT_LOGIN_ATTEMPTS: Maximum attempts (default: 5)
     * - RATE_LIMIT_LOGIN_WINDOW: Window in seconds (default: 900 = 15 minutes)
     * - RATE_LIMIT_STORAGE_PATH: Path for storage (default: system temp dir)
     *
     * @return RateLimiter
     */
    public static function fromEnv(): RateLimiter
    {
        $maxAttempts   = 5;
        $windowSeconds = 900;
        $storagePath   = null;

        $attemptsEnv = $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? null;
        if ($attemptsEnv !== null && is_numeric($attemptsEnv)) {
            $maxAttempts = max(1, (int) $attemptsEnv);
        }

        $windowEnv = $_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? null;
        if ($windowEnv !== null && is_numeric($windowEnv)) {
            $windowSeconds = max(60, (int) $windowEnv); // Minimum 60 seconds
        }

        $storageEnv = $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? null;
        if ($storageEnv !== null && is_string($storageEnv) && $storageEnv !== '') {
            $storagePath = $storageEnv;
        }

        return new RateLimiter($maxAttempts, $windowSeconds, $storagePath);
    }
}
