<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\JsonFileRecipeRepository;
use App\Repository\RepositoryFactory;
use App\Repository\SqliteRecipeRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepositoryFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-factory-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testCreatesTheConfiguredBackend(): void
    {
        self::assertInstanceOf(JsonFileRecipeRepository::class, $this->factory('json')->create());
        self::assertInstanceOf(SqliteRecipeRepository::class, $this->factory('sqlite')->create());
        self::assertInstanceOf(SqliteRecipeRepository::class, $this->factory('json')->create('sqlite'));
    }

    public function testExistsOnlyAfterStorageWasCreated(): void
    {
        $factory = $this->factory('sqlite');
        self::assertFalse($factory->exists());
        $factory->create();
        self::assertTrue($factory->exists());
        self::assertFalse($factory->exists('json'));
    }

    public function testRejectsUnknownStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory('mysql')->create();
    }

    private function factory(string $storage): RepositoryFactory
    {
        return new RepositoryFactory([
            'storage' => $storage,
            'json_file' => $this->dir . '/recipes.json',
            'sqlite_file' => $this->dir . '/recipes.sqlite',
        ]);
    }
}
