<?php

declare(strict_types=1);

namespace Tests;

use App\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreLoadedFirstAndCallerOverridesWinLast(): void
    {
        $config = Config::load(['storage' => 'sqlite', 'base_url' => '/recipebox']);

        self::assertSame('sqlite', $config['storage']);
        self::assertSame('/recipebox', $config['base_url']);
        self::assertArrayHasKey('llm_endpoint', $config, 'the shipped defaults are loaded');
        self::assertArrayHasKey('auth_enabled', $config, 'the shipped defaults are loaded');
    }

    public function testSectionReturnsKeysWithoutTheirPrefix(): void
    {
        $section = Config::section([
            'storage' => 'json',
            'auth_enabled' => false,
            'auth_state_dir' => '/tmp/state',
        ], 'auth');

        self::assertSame(['enabled' => false, 'state_dir' => '/tmp/state'], $section);
    }

    public function testShippedConfigurationIsFlat(): void
    {
        $defaults = require BASEDIR . '/defaults/config.php';
        $local = require CONFDIR . '/config.php';

        self::assertSame([], array_filter($defaults, 'is_array'), 'defaults must not contain nested arrays');
        self::assertSame([], array_filter($local, 'is_array'), 'local config must not contain nested arrays');
        self::assertSame([], array_diff_key($local, $defaults), 'local config may only override known settings');
    }

    public function testEnvironmentVariablesStillOverrideTheConfigurationAndCallerOverridesWin(): void
    {
        $storage = getenv('RECIPES_STORAGE');
        $auth = getenv('RECIPES_AUTH');

        putenv('RECIPES_STORAGE=sqlite');
        putenv('RECIPES_AUTH=0');

        try {
            $config = Config::load();
            self::assertSame('sqlite', $config['storage']);
            self::assertFalse($config['auth_enabled']);

            self::assertSame('json', Config::load(['storage' => 'json'])['storage'], 'caller overrides are applied last');
        } finally {
            putenv($storage === false ? 'RECIPES_STORAGE' : 'RECIPES_STORAGE=' . $storage);
            putenv($auth === false ? 'RECIPES_AUTH' : 'RECIPES_AUTH=' . $auth);
        }
    }
}
