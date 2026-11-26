<?php

declare(strict_types=1);

namespace Tests;

use App\Config\ConfigSchema;
use PHPUnit\Framework\TestCase;

final class ConfigSchemaTest extends TestCase
{
    public function testEveryShippedSettingGetsAControl(): void
    {
        $defaults = require BASEDIR . '/defaults/config.php';

        foreach ($defaults as $key => $default) {
            $meta = ConfigSchema::describe((string) $key, $default);
            self::assertContains($meta['type'], ['string', 'textarea', 'onoff', 'int', 'multichoice', 'password'], (string) $key);
            self::assertNotSame('', $meta['label'], (string) $key);
        }
    }

    public function testNewSettingsAreInferredFromTheirDefaultValue(): void
    {
        self::assertSame('onoff', ConfigSchema::describe('brand_new_flag', true)['type']);
        self::assertSame('onoff', ConfigSchema::describe('brand_new_flag', false)['type']);
        self::assertSame('int', ConfigSchema::describe('brand_new_count', 5)['type']);
        self::assertSame('string', ConfigSchema::describe('brand_new_text', 'value')['type']);
        self::assertTrue(ConfigSchema::describe('brand_new_nullable', null)['nullable']);
    }

    public function testKnownSettingsUseTheExpectedControls(): void
    {
        self::assertSame('onoff', ConfigSchema::describe('auth_enabled', true)['type']);
        self::assertSame('textarea', ConfigSchema::describe('llm_prompt', '')['type']);
        self::assertSame('int', ConfigSchema::describe('llm_timeout', 60)['type']);
        self::assertSame('multichoice', ConfigSchema::describe('storage', 'json')['type']);
        self::assertTrue(ConfigSchema::describe('base_url', null)['nullable']);
        self::assertSame(1, ConfigSchema::describe('llm_timeout', 60)['min']);
    }

    public function testChoiceValuesKeepTheirType(): void
    {
        $choices = ConfigSchema::describe('auth_cookie_secure', 'auto')['choices'];
        $byKey = [];
        foreach ($choices as $choice) {
            $byKey[$choice['key']] = $choice;
        }

        self::assertSame('auto', $byKey['auto']['value']);
        self::assertSame('Automatic', $byKey['auto']['label']);
        self::assertTrue($byKey['true']['value']);
        self::assertSame('Always', $byKey['true']['label']);
        self::assertFalse($byKey['false']['value']);
    }

    public function testSettingsAreGroupedByTheirPrefix(): void
    {
        $groups = ConfigSchema::groups(['storage', 'llm_endpoint', 'auth_enabled', 'auth_lockout_seconds']);

        self::assertSame(['', 'auth_', 'llm_'], array_column($groups, 'prefix'));
        self::assertSame(['storage'], $groups[0]['keys']);
        self::assertSame(['auth_enabled', 'auth_lockout_seconds'], $groups[1]['keys']);
        self::assertSame(['llm_endpoint'], $groups[2]['keys']);
    }
}
