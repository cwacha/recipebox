<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Config\ConfigSchema;
use App\Config\ConfigStore;
use App\Controller\InstallController;
use App\Http\Request;
use App\Install\Requirements;
use App\Repository\JsonFileRecipeRepository;
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase
{
    private string $dir;
    private string $localConfig;
    private ConfigStore $configStore;
    private Application $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-install-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0775, true);
        mkdir($this->dir . '/defaults', 0775, true);
        copy(BASEDIR . '/defaults/config.php', $this->dir . '/defaults/config.php');

        $this->localConfig = $this->dir . '/config/config.php';
        $this->configStore = new ConfigStore($this->dir . '/defaults/config.php', $this->localConfig);
        $this->app = $this->application();
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testAFreshInstallationShowsTheRequirementChecks(): void
    {
        $response = $this->app->handle(new Request('GET', '/', ''));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Welcome to Recipe Box', $response->body());
        self::assertStringContainsString('Version ' . trim((string) file_get_contents(BASEDIR . '/VERSION')), $response->body());
        self::assertStringContainsString('PHP extensions', $response->body());
        self::assertStringContainsString('Directories', $response->body());
        self::assertStringContainsString('Continue', $response->body());
        self::assertStringNotContainsString('New recipe', $response->body());
    }

    public function testTheSetupFormShowsTheInitialConfiguration(): void
    {
        $response = $this->app->handle(new Request('GET', '/install/config', ''));
        $body = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Initial configuration', $body);
        self::assertStringContainsString('Finish setup', $body);

        // Like /admin/config, the form is generated from every setting in defaults/config.php.
        foreach (array_keys($this->configStore->defaults()) as $key) {
            self::assertStringContainsString('name="config[' . $key . ']"', $body, $key . ' is rendered');
        }

        self::assertStringContainsString('General', $body);
        self::assertStringContainsString('Authentication and access control', $body);
        self::assertStringContainsString('Import from a URL (AI)', $body);
        self::assertStringContainsString('<textarea', $body, 'textarea settings such as llm_prompt are rendered as textareas');
        self::assertStringContainsString('name="config[llm_prompt]"', $body);
        self::assertStringContainsString('placeholder="https://api.openai.com/v1/chat/completions"', $body);
        self::assertStringContainsString('placeholder="recipes.json"', $body);
        self::assertStringContainsString('placeholder="Turn this web page into a recipe', $body);
        self::assertStringContainsString('placeholder="Detected automatically"', $body);
        self::assertStringContainsString('placeholder="120"', $body, 'integer settings use their default as placeholder');
    }

    public function testSavingTheConfigurationCompletesTheInstallation(): void
    {
        $response = $this->app->handle(new Request('POST', '/install/config', '', post: ['config' => [
            'storage' => 'json',
            'base_url__null' => '1',
            'base_url' => '',
            'import_allow_private_hosts' => '0',
            'auth_enabled' => '0',
            'auth_guest_account' => '',
            'auth_min_password_length' => '10',
            'llm_endpoint' => 'https://example.test/v1/chat/completions',
            'llm_model' => 'test-model',
            'llm_api_key' => 'test-secret',
        ]]));

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->headers()['Location']);
        self::assertFileExists($this->localConfig);

        $local = $this->configStore->local();
        self::assertFalse($local['auth_enabled']);
        self::assertSame('', $local['auth_guest_account']);
        self::assertSame(10, $local['auth_min_password_length']);
        self::assertSame('https://example.test/v1/chat/completions', $local['llm_endpoint']);
        self::assertSame('test-model', $local['llm_model']);
        self::assertSame('test-secret', $local['llm_api_key']);
        self::assertArrayNotHasKey('base_url', $local, 'the automatic value equals the default');

        // A new request now sees a configured installation and serves the application instead of the setup.
        $installed = $this->application()->handle(new Request('GET', '/', ''));
        self::assertSame(200, $installed->status());
        self::assertStringNotContainsString('Welcome to Recipe Box', $installed->body());
    }

    public function testTheSavedConfigurationIsUsedByTheApplication(): void
    {
        $this->app->handle(new Request('POST', '/install/config', '', post: ['config' => [
            'storage' => 'json',
            'auth_guest_account' => '',
        ]]));

        self::assertSame('', $this->configStore->local()['auth_guest_account']);

        // Guests are no longer allowed, so the configured application sends visitors to the login page.
        $response = $this->application()->handle(new Request('GET', '/', ''));
        self::assertSame(302, $response->status());
        self::assertStringStartsWith('/login', (string) $response->headers()['Location']);
    }

    public function testTheWholeGeneratedFormCanBeSubmittedWithoutChanges(): void
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

        $response = $this->app->handle(new Request('POST', '/install/config', '', post: ['config' => $post]));

        self::assertSame(303, $response->status());
        self::assertSame([], $this->configStore->local());
        self::assertFileExists($this->localConfig);
    }

    public function testSqliteCanBeSelectedWhenTheExtensionIsAvailable(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $response = $this->app->handle(new Request('POST', '/install/config', '', post: ['config' => [
            'storage' => 'sqlite',
        ]]));

        self::assertSame(303, $response->status());
        self::assertSame('sqlite', $this->configStore->local()['storage']);
    }

    public function testTheConfigurationCannotBeOpenedOrSavedWhileChecksFail(): void
    {
        $requirements = new Requirements(
            baseDir: $this->dir,
            defaultsDir: $this->dir . '/defaults',
            publicDir: $this->dir . '/public',
            configDir: $this->dir . '/config',
            stateDir: $this->dir . '/missing/state',
            sessionsDir: $this->dir . '/var/sessions',
            imageDir: $this->dir . '/public/images',
            uploadDir: $this->dir . '/public/uploads',
        );
        $controller = new InstallController(BASEDIR . '/templates', $this->configStore, $requirements);

        $check = $controller->check(new Request('GET', '/install', ''));
        self::assertSame(200, $check->status());
        self::assertStringContainsString('Some requirements are not met', $check->body());
        self::assertStringNotContainsString('href="/install/config"', $check->body());

        $config = $controller->config(new Request('GET', '/install/config', ''));
        self::assertSame(302, $config->status());
        self::assertSame('/install', $config->headers()['Location']);

        $save = $controller->save(new Request('POST', '/install/config', '', post: ['config' => ['storage' => 'json']]));
        self::assertSame(302, $save->status());
        self::assertFileDoesNotExist($this->localConfig);
    }

    public function testAnInvalidStorageChoiceKeepsTheFormOpen(): void
    {
        $response = $this->app->handle(new Request('POST', '/install/config', '', post: ['config' => [
            'storage' => 'bogus',
        ]]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('allowed values', $response->body());
        self::assertFileDoesNotExist($this->localConfig);
    }

    private function application(): Application
    {
        return new Application(
            new JsonFileRecipeRepository($this->dir . '/recipes.json'),
            configStore: $this->configStore,
        );
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);
        }
    }
}
