<?php

declare(strict_types=1);

namespace App\Auth;

/** Slows down password guessing: too many failures for a key lock it for a while. */
final class LoginThrottle
{
    public function __construct(
        private readonly JsonFile $file,
        private readonly int $window = 900,
    ) {
    }

    /** @return int seconds until the key may try again, 0 if it is not locked */
    public function retryAfter(string $key): int
    {
        $entry = $this->file->read()[$this->hash($key)] ?? null;

        return is_array($entry) ? max(0, (int) ($entry['locked_until'] ?? 0) - time()) : 0;
    }

    public function fail(string $key, int $limit): void
    {
        $id = $this->hash($key);
        $now = time();

        $this->file->modify(function (array $entries) use ($id, $limit, $now): array {
            $entries = array_filter($entries, static fn (mixed $e): bool => is_array($e) && (int) ($e['expires'] ?? 0) > $now);
            $entry = $entries[$id] ?? ['count' => 0];
            $count = (int) $entry['count'] + 1;
            $entries[$id] = [
                'count' => $count,
                'locked_until' => $count >= $limit ? $now + $this->window : 0,
                'expires' => $now + $this->window,
            ];

            return $entries;
        });
    }

    public function clear(string $key): void
    {
        $id = $this->hash($key);
        if (!isset($this->file->read()[$id])) {
            return;
        }

        $this->file->modify(static function (array $entries) use ($id): array {
            unset($entries[$id]);

            return $entries;
        });
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
