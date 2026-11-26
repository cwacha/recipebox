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
            'auth_remember_days' => 14,
        ], 'auth');

        self::assertSame(['enabled' => false, 'remember_days' => 14], $section);
    }

    public function testShippedConfigurationIsFlat(): void
    {
        $defaults = require BASEDIR . '/defaults/config.php';
        $local = require CONFDIR . '/config.php';

        self::assertSame([], array_filter($defaults, 'is_array'), 'defaults must not contain nested arrays');
        self::assertSame([], array_filter($local, 'is_array'), 'local config must not contain nested arrays');
        self::assertSame([], array_diff_key($local, $defaults), 'local config may only override known settings');
    }
}
