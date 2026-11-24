<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exception\StorageFailed;
use JsonException;

/** A JSON object stored in a file. Changes are applied under an exclusive lock and written atomically (mode 0600). */
final class JsonFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> empty if the file does not exist */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = @file_get_contents($this->path);
        if ($json === false) {
            throw new StorageFailed('Cannot read ' . basename($this->path));
        }
        if (trim($json) === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageFailed('Invalid JSON in ' . basename($this->path) . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new StorageFailed(basename($this->path) . ' does not contain a JSON object');
        }

        return $data;
    }

    /**
     * Reads the current content under a lock, lets $change return the new content and writes it.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    public function modify(callable $change): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new StorageFailed('Cannot create directory ' . $dir);
        }

        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new StorageFailed('Cannot lock ' . basename($this->path));
        }

        try {
            $this->write($change($this->read()));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $json = $data === []
            ? "{}\n"
            : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new StorageFailed('Cannot write ' . basename($this->path));
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new StorageFailed('Cannot replace ' . basename($this->path));
        }
    }
}
