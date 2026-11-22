<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Simple configuration manager.
 * Loads config from config/config.php and provides access via config() helper.
 */
final class Config
{
    private static array $data = [];

    /**
     * Load configuration array
     */
    public static function load(array $config): void
    {
        self::$data = $config;
    }

    /**
     * Get configuration value by dot notation
     * Example: config('app.env') returns $config['app']['env']
     */
    public static function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = self::$data;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Check if configuration key exists
     */
    public static function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = self::$data;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Get all configuration
     */
    public static function all(): array
    {
        return self::$data;
    }
}
