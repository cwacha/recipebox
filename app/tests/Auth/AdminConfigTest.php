<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Config\ConfigSchema;
use App\Config\ConfigStore;

final class AdminConfigTest extends AuthWebTestCase
{
    private Browser $admin;
    private string $localConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localConfig = $this->dir . '/config/config.php';
        mkdir($this->dir . '/config', 0775, true);
        copy(BASEDIR . '/defaults/config.php', $this->dir . '/defaults/config.php');
        file_put_contents($this->localConfig, "<?php\n\nreturn [];\n");

        $this->configStore = new ConfigStore($this->dir . '/defaults/config.php', $this->localConfig);
        $this->app = $this->app();
        $this->admin = $this->loggedIn('admin', 'adminpass1');
    }

    public function testThePageIsGeneratedFromTheDefaults(): void
    {
        $body = $this->admin->get('/admin/config')->body();

        self::assertStringContainsString('Configuration', $body);
        self::assertStringContainsString('config[base_url]', $body);
        self::assertStringContainsString('config[storage]', $body);
        self::assertStringContainsString('config[llm_prompt]', $body);
        self::assertStringNotContainsString('config[auth_defaults_dir]', $body);
        self::assertStringNotContainsString('config[auth_state_dir]', $body);
        self::assertStringNotContainsString('config[auth_sessions_dir]', $body);
        self::assertStringContainsString('config[auth_cookie_secure]', $body);
        self::assertStringContainsString('General', $body);
        self::assertStringContainsString('Import from a URL (AI)', $body);
        self::assertStringContainsString('Authentication and access control', $body);
        self::assertStringContainsString('Save configuration', $body);
        self::assertStringContainsString('Everything uses the shipped defaults', $body);
    }

    public function testOnlyAccountsWithTheViewPermissionCanOpenThePage(): void
    {
        $reader = $this->loggedIn('rd', 'readerpass1');
        self::assertSame(302, $reader->get('/admin/config')->status());
        self::assertSame(302, $this->browser()->get('/admin/config')->status());
    }

    public function testChangedValuesAreHighlightedAndShowTheirDefault(): void
    {
        file_put_contents($this->localConfig, "<?php\n\nreturn " . var_export([
            'auth_enabled' => false,
            'storage' => 'sqlite',
        ], true) . ";\n");

        $body = $this->admin->get('/admin/config')->body();

        self::assertGreaterThanOrEqual(2, substr_count($body, 'is-changed'));
        self::assertStringContainsString('2 changed', $body);
        self::assertStringContainsString('Default: <code>true</code>', $body);
        self::assertStringContainsString('Default: <code>json</code>', $body);
    }

    public function testSavingWritesOnlyDifferencesAndResettingRemovesThem(): void
    {
        $response = $this->admin->post('/admin/config', ['config' => [
            'auth_enabled' => '0',
            'storage' => 'sqlite',
            'llm_endpoint' => 'https://example.test/v1/chat/completions',
        ]]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/config', Browser::location($response));
        self::assertStringContainsString('Only the differences', $this->flashMessages($this->admin));

        $local = $this->configStore->local();
        self::assertSame(false, $local['auth_enabled']);
        self::assertSame('sqlite', $local['storage']);
        self::assertSame('https://example.test/v1/chat/completions', $local['llm_endpoint']);
        self::assertArrayNotHasKey('llm_model', $local);
        self::assertArrayNotHasKey('auth_guest_account', $local);

        $contents = (string) file_get_contents($this->localConfig);
        self::assertStringContainsString('managed by the application', $contents);
        self::assertStringContainsString('only the settings that differ', $contents);
        self::assertStringNotContainsString("'llm_model'", $contents);

        $reset = $this->admin->post('/admin/config', ['config' => [
            'auth_enabled' => '1',
            'storage' => 'json',
            'llm_endpoint' => '',
        ]]);

        self::assertSame(303, $reset->status());
        self::assertSame([], $this->configStore->local());
    }

    public function testValidation(): void
    {
        $invalidInt = $this->admin->post('/admin/config', ['config' => ['llm_timeout' => 'abc']]);
        self::assertSame(422, $invalidInt->status());
        self::assertStringContainsString('whole number', $invalidInt->body());
        self::assertSame([], $this->configStore->local());

        $invalidChoice = $this->admin->post('/admin/config', ['config' => ['storage' => 'bogus']]);
        self::assertSame(422, $invalidChoice->status());
        self::assertStringContainsString('allowed values', $invalidChoice->body());

        $emptyBaseUrl = $this->admin->post('/admin/config', ['config' => [
            'base_url' => '',
            'base_url__null' => '0',
        ]]);
        self::assertSame(303, $emptyBaseUrl->status());
        self::assertSame('', $this->configStore->local()['base_url']);
    }

    public function testTheActionPermissionIsRequiredForSaving(): void
    {
        $this->admin->post('/admin/role/edit', [
            'mode' => 'new',
            'id' => 'configviewer',
            'permissions' => ['show', '/', '/admin', '/admin/config'],
        ]);
        $this->admin->post('/admin/account/edit', [
            'mode' => 'new',
            'id' => 'cfg',
            'password' => 'cfg-password',
            'confirm_password' => 'cfg-password',
            'roles' => ['configviewer'],
        ]);

        $viewer = $this->loggedIn('cfg', 'cfg-password');
        $viewerPage = $viewer->get('/admin/config');
        self::assertSame(200, $viewerPage->status());
        self::assertStringNotContainsString('Save configuration', $viewerPage->body());
        self::assertStringContainsString('do not have permission to save', $viewerPage->body());
        self::assertSame(302, $viewer->post('/admin/config', ['config' => ['llm_model' => 'x']])->status());
        self::assertSame([], $this->configStore->local());

        $this->admin->post('/admin/role/edit', [
            'mode' => 'edit',
            'id' => 'configviewer',
            'permissions' => ['show', '/', '/admin', '/admin/config', 'config_edit'],
        ]);

        self::assertSame(303, $viewer->post('/admin/config', ['config' => ['llm_model' => 'test-model']])->status());
        self::assertSame('test-model', $this->configStore->local()['llm_model']);
    }

    public function testTypedValuesAreSavedAndReadBack(): void
    {
        $response = $this->admin->post('/admin/config', ['config' => [
            'llm_timeout' => '42',
            'llm_prompt' => "Line one\nLine two",
            'auth_cookie_secure' => 'false',
        ]]);

        self::assertSame(303, $response->status());

        $local = $this->configStore->local();
        self::assertSame(42, $local['llm_timeout']);
        self::assertSame("Line one\nLine two", $local['llm_prompt']);
        self::assertFalse($local['auth_cookie_secure']);

        // The generated file must be valid PHP with the same values.
        self::assertStringContainsString("'llm_timeout' => 42", (string) file_get_contents($this->localConfig));
    }

    public function testSubmittingTheWholeFormWithoutChangesWritesNothing(): void
    {
        $post = [];
        foreach ($this->configStore->defaults() as $key => $default) {
            $meta = ConfigSchema::describe((string) $key, $default);

            if ($meta['type'] === 'onoff') {
                $post[$key] = $default ? '1' : '0';
                continue;
            }
            if ($meta['type'] === 'multichoice') {
                foreach ($meta['choices'] as $choice) {
                    if ($choice['value'] === $default) {
                        $post[$key] = $choice['key'];
                        break;
                    }
                }
                continue;
            }
            if ($meta['type'] === 'password') {
                $post[$key] = '';
                continue;
            }
            if ($meta['nullable']) {
                $post[$key . '__null'] = $default === null ? '1' : '0';
                $post[$key] = $default === null ? '' : (string) $default;
                continue;
            }
            $post[$key] = (string) $default;
        }

        $response = $this->admin->post('/admin/config', ['config' => $post]);

        self::assertSame(303, $response->status());
        self::assertSame([], $this->configStore->local());
    }

    public function testSecretsAreNotSentToTheBrowser(): void
    {
        file_put_contents($this->localConfig, "<?php\n\nreturn " . var_export([
            'llm_api_key' => 'super-secret',
        ], true) . ";\n");

        $body = $this->admin->get('/admin/config')->body();
        self::assertStringNotContainsString('super-secret', $body);
        self::assertStringContainsString('A value is stored', $body);

        $this->admin->post('/admin/config', ['config' => ['llm_api_key' => '']]);
        self::assertSame('super-secret', $this->configStore->local()['llm_api_key']);

        $this->admin->post('/admin/config', ['config' => ['llm_api_key' => '', 'llm_api_key__clear' => '1']]);
        self::assertArrayNotHasKey('llm_api_key', $this->configStore->local());
    }

    public function testTheAllRoleShipsWithTheConfigurationPermissions(): void
    {
        $roles = json_decode((string) file_get_contents(BASEDIR . '/defaults/roles.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('/admin/config', explode(',', $roles['all']));
        self::assertContains('config_edit', explode(',', $roles['all']));
    }

    public function testUnknownSettingsCannotBeInjected(): void
    {
        $response = $this->admin->post('/admin/config', ['config' => ['evil' => 'injected']]);

        self::assertSame(303, $response->status());
        self::assertSame([], $this->configStore->local());
    }
}
