<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
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
        self::assertStringContainsString('PHP extensions', $response->body());
        self::assertStringContainsString('Directories', $response->body());
        self::assertStringContainsString('Continue', $response->body());
        self::assertStringNotContainsString('New recipe', $response->body());
    }

    public function testTheSetupFormShowsTheInitialConfiguration(): void
    {
        $response = $this->app->handle(new Request('GET', '/install/config', ''));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Initial configuration', $response->body());
        self::assertStringContainsString('name="config[storage]"', $response->body());
        self::assertStringContainsString('name="config[auth_enabled]"', $response->body());
        self::assertStringContainsString('name="config[llm_endpoint]"', $response->body());
        self::assertStringContainsString('Finish setup', $response->body());
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
