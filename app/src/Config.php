<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Loads the configuration in four layers: the shipped defaults in defaults/config.php, the local
 * overrides in config/config.php, environment variables, and finally any overrides passed by the caller.
 *
 * Environment variables stay supported even though config/config.php is now managed by the
 * administration: saving the configuration rewrites that file with plain differences, it does not
 * bake environment values into it.
 */
final class Config
{
    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function load(array $overrides = []): array
    {
        $defaultsFile = BASEDIR . '/defaults/config.php';
        if (!is_file($defaultsFile)) {
            throw new RuntimeException('Missing default configuration: ' . $defaultsFile);
        }

        $defaults = require $defaultsFile;
        $localFile = CONFDIR . '/config.php';
        $local = is_file($localFile) ? require $localFile : [];

        if (!is_array($defaults) || !is_array($local)) {
            throw new RuntimeException('Configuration files must return an array.');
        }

        return array_replace($defaults, $local, $overrides);
    }

    /**
     * Returns the keys of one section without their prefix, e.g. auth_enabled => enabled.
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function section(array $config, string $prefix): array
    {
        $section = [];
        $prefix .= '_';
        foreach ($config as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $section[substr($key, strlen($prefix))] = $value;
            }
        }

        return $section;
    }
}
