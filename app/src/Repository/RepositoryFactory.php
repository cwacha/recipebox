<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;

/** Creates the configured recipe repository (see defaults/config.php and config/config.php). */
final class RepositoryFactory
{
    public const DRIVERS = ['json', 'sqlite'];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function driver(): string
    {
        return (string) ($this->config['storage'] ?? 'json');
    }

    public function create(?string $driver = null): RecipeRepositoryInterface
    {
        $driver ??= $this->driver();
        $file = $this->file($driver);

        return match ($driver) {
            'json' => new JsonFileRecipeRepository($file),
            'sqlite' => new SqliteRecipeRepository($file),
        };
    }

    /** Whether the storage has been created before (before the first start nothing exists yet). */
    public function exists(?string $driver = null): bool
    {
        return is_file($this->file($driver ?? $this->driver()));
    }

    public function file(string $driver): string
    {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown storage "%s". Use one of: %s.',
                $driver,
                implode(', ', self::DRIVERS),
            ));
        }

        // The configured values are only file names; the state directory is always added here.
        // basename() keeps older configurations that still contain a full path working.
        $name = basename((string) $this->config[$driver . '_file']);

        return STATEDIR . '/' . $name;
    }
}
