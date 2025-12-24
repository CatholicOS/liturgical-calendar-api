<?php

namespace LiturgicalCalendar\Api;

/**
 * Environment utility for checking application environment settings
 *
 * Centralizes environment detection logic to maintain DRY principles
 * across middleware, services, and other components.
 *
 * @package LiturgicalCalendar\Api
 */
class Environment
{
    /**
     * Get the current environment name.
     *
     * Centralizes APP_ENV normalization (lowercase, trimmed) to avoid
     * subtle drift between environment check methods.
     *
     * @return string The environment name (lowercase, trimmed).
     */
    public static function getName(): string
    {
        $appEnv = $_ENV['APP_ENV'] ?? 'development';

        return is_string($appEnv) ? strtolower(trim($appEnv)) : 'development';
    }

    /**
     * Check if the current environment is a production-like environment.
     *
     * @return bool True if APP_ENV is 'staging' or 'production'.
     */
    public static function isProduction(): bool
    {
        return in_array(self::getName(), ['staging', 'production'], true);
    }

    /**
     * Check if the current environment is a development environment.
     *
     * @return bool True if APP_ENV is 'development' or 'test'.
     */
    public static function isDevelopment(): bool
    {
        return in_array(self::getName(), ['development', 'test'], true);
    }
}
