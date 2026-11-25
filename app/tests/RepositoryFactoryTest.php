<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\RepositoryFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepositoryFactoryTest extends TestCase
{
    public function testUsesTheConfiguredDriver(): void
    {
        self::assertSame('json', $this->factory('json')->driver());
        self::assertSame('sqlite', $this->factory('sqlite')->driver());
    }

    public function testRejectsUnknownStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory('mysql')->create();
    }

    public function testPutsOnlyTheBasenameIntoTheStateDirectory(): void
    {
        $factory = new RepositoryFactory([
            'storage' => 'json',
            'json_file' => '/somewhere/else/custom-recipes.json',
            'sqlite_file' => 'databases/custom-recipes.sqlite',
        ]);

        self::assertSame(STATEDIR . '/custom-recipes.json', $factory->file('json'));
        self::assertSame(STATEDIR . '/custom-recipes.sqlite', $factory->file('sqlite'));
    }

    public function testUsesTheGlobalStateDirectory(): void
    {
        self::assertSame(STATEDIR . '/recipes.json', $this->factory('json')->file('json'));
        self::assertSame(STATEDIR . '/recipes.sqlite', $this->factory('sqlite')->file('sqlite'));
    }

    private function factory(string $storage): RepositoryFactory
    {
        return new RepositoryFactory([
            'storage' => $storage,
            'json_file' => 'recipes.json',
            'sqlite_file' => 'recipes.sqlite',
        ]);
    }
}
