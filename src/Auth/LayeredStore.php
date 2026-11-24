<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Records that ship with the application (defaults) plus local changes on top.
 *
 * The local file only contains the differences: changed or added records, and `null` for default records that
 * were removed. Defaults are never modified. On save only the records changed through this instance are
 * touched, so unrelated local entries (and changes made concurrently by someone else) are kept.
 */
final class LayeredStore
{
    /** @var array<string, mixed> */
    private array $defaults = [];
    /** @var array<string, mixed> */
    private array $current = [];
    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly JsonFile $defaultsFile,
        private readonly JsonFile $localFile,
    ) {
    }

    public function load(): void
    {
        $this->defaults = $this->defaultsFile->read();
        $this->current = $this->defaults;
        foreach ($this->localFile->read() as $id => $value) {
            if ($value === null) {
                unset($this->current[$id]);
            } else {
                $this->current[$id] = $value;
            }
        }
        $this->pending = [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->current;
    }

    public function get(string $id): mixed
    {
        return $this->current[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->current);
    }

    public function isDefault(string $id): bool
    {
        return array_key_exists($id, $this->defaults);
    }

    public function set(string $id, mixed $value): void
    {
        $this->current[$id] = $value;
        $this->pending[$id] = true;
    }

    public function remove(string $id): void
    {
        unset($this->current[$id]);
        $this->pending[$id] = true;
    }

    public function save(): void
    {
        if ($this->pending === []) {
            return;
        }

        $ids = array_map('strval', array_keys($this->pending));
        $this->localFile->modify(function (array $local) use ($ids): array {
            foreach ($ids as $id) {
                if (array_key_exists($id, $this->current)) {
                    if (array_key_exists($id, $this->defaults) && $this->defaults[$id] == $this->current[$id]) {
                        unset($local[$id]);
                    } else {
                        $local[$id] = $this->current[$id];
                    }
                } elseif (array_key_exists($id, $this->defaults)) {
                    $local[$id] = null;
                } else {
                    unset($local[$id]);
                }
            }

            return $local;
        });

        $this->pending = [];
    }
}
