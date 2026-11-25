<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Reads defaults/config.php and config/config.php and writes the local file as a difference.
 *
 * Only settings whose value differs from the shipped default are stored. Resetting a setting to
 * its default therefore removes it from the local file again, and an upgrade can change a default
 * without an old copy in config/config.php hiding it.
 */
final class ConfigStore
{
    public function __construct(
        private readonly string $defaultsFile,
        private readonly string $localFile,
    ) {
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return $this->read($this->defaultsFile, true);
    }

    /** @return array<string, mixed> only the local differences */
    public function local(): array
    {
        return $this->read($this->localFile, false);
    }

    /** @return array<string, mixed> defaults with the local differences applied */
    public function current(): array
    {
        return array_replace($this->defaults(), $this->local());
    }

    /**
     * Writes only the values that differ from the defaults. Keys that are not part of the defaults
     * are ignored, so the local file cannot grow settings the application does not know.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $defaults = $this->defaults();
        $local = [];

        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $values) && $values[$key] !== $default) {
                $local[$key] = $values[$key];
            }
        }

        $this->write($local);
    }

    /** @return array<string, mixed> */
    private function read(string $file, bool $required): array
    {
        if (!is_file($file)) {
            if ($required) {
                throw new RuntimeException('Missing configuration file: ' . $file);
            }

            return [];
        }

        $data = require $file;
        if (!is_array($data)) {
            throw new RuntimeException('Configuration files must return an array: ' . $file);
        }

        return $data;
    }

    /** @param array<string, mixed> $local */
    private function write(array $local): void
    {
        $dir = dirname($this->localFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the configuration directory: ' . $dir);
        }

        $contents = $this->export($local);
        $temp = $this->localFile . '.tmp.' . bin2hex(random_bytes(6));
        $mode = is_file($this->localFile) ? (fileperms($this->localFile) & 0777) : 0640;

        try {
            if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write ' . $this->localFile . '. Check the permissions of the config directory.');
            }
            @chmod($temp, $mode);

            if (!@rename($temp, $this->localFile)) {
                throw new RuntimeException('Cannot replace ' . $this->localFile . '. Check the permissions of the config directory.');
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->localFile, true);
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** @param array<string, mixed> $local */
    private function export(array $local): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/*',
            ' * Local configuration.',
            ' *',
            ' * This file is managed by the application (Administration -> Configuration).',
            ' * It contains only the settings that differ from defaults/config.php; everything',
            ' * else is taken from the defaults. Manual changes are read, but the file is',
            ' * rewritten when the configuration is saved.',
            ' */',
            '',
            'return [',
        ];

        foreach ($local as $key => $value) {
            $lines[] = '    ' . var_export((string) $key, true) . ' => ' . var_export($value, true) . ',';
        }

        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }
}
