<?php

declare(strict_types=1);

namespace Tests;

use App\Install\Requirements;
use PHPUnit\Framework\TestCase;

final class InstallRequirementsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-requirements-test-' . bin2hex(random_bytes(4));
        $this->structure();
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testACompleteInstallationPassesAllRequiredChecks(): void
    {
        $report = $this->requirements()->run();

        self::assertTrue($report['ok']);
        self::assertSame(PHP_VERSION, $report['php']);
        self::assertTrue($this->check($report, 'PHP version')['ok']);
        self::assertTrue($this->check($report, 'JSON')['ok']);
        self::assertTrue($this->check($report, 'config/')['ok']);
        self::assertTrue($this->check($report, 'vendor/autoload.php')['ok']);
    }

    public function testMissingDirectoriesAreReported(): void
    {
        $report = (new Requirements(
            baseDir: $this->dir,
            defaultsDir: $this->dir . '/defaults',
            publicDir: $this->dir . '/public',
            configDir: $this->dir . '/config',
            stateDir: $this->dir . '/does-not-exist/state',
            sessionsDir: $this->dir . '/var/sessions',
            imageDir: $this->dir . '/public/images',
            uploadDir: $this->dir . '/public/uploads',
        ))->run();

        self::assertFalse($report['ok']);
        self::assertFalse($this->check($report, 'var/state/')['ok']);
        self::assertSame('missing', $this->check($report, 'var/state/')['detail']);
    }

    public function testMissingApplicationFilesAreReported(): void
    {
        unlink($this->dir . '/defaults/accounts.json');

        $report = $this->requirements()->run();

        self::assertFalse($report['ok']);
        self::assertFalse($this->check($report, 'defaults/accounts.json')['ok']);
    }

    public function testDirectoriesThatCanBeCreatedCountAsReady(): void
    {
        $report = (new Requirements(
            baseDir: $this->dir,
            defaultsDir: $this->dir . '/defaults',
            publicDir: $this->dir . '/public',
            configDir: $this->dir . '/config/new',
            stateDir: $this->dir . '/var/state',
            sessionsDir: $this->dir . '/var/sessions',
            imageDir: $this->dir . '/public/images',
            uploadDir: $this->dir . '/public/uploads',
        ))->run();

        $check = $this->check($report, 'config/');
        self::assertTrue($check['ok']);
        self::assertSame('missing, will be created', $check['detail']);
        self::assertTrue($report['ok']);
    }

    private function requirements(): Requirements
    {
        return new Requirements(
            baseDir: $this->dir,
            defaultsDir: $this->dir . '/defaults',
            publicDir: $this->dir . '/public',
            configDir: $this->dir . '/config',
            stateDir: $this->dir . '/var/state',
            sessionsDir: $this->dir . '/var/sessions',
            imageDir: $this->dir . '/public/images',
            uploadDir: $this->dir . '/public/uploads',
        );
    }

    private function structure(): void
    {
        $dirs = [
            'config',
            'var/state',
            'var/sessions',
            'public/images',
            'public/uploads',
            'public/assets/css',
            'public/assets/vendor/bootstrap/css',
            'public/assets/vendor/bootstrap/js',
            'public/assets/vendor/bootstrap-icons',
            'public/assets/vendor/jquery',
            'defaults/seed',
            'vendor',
            'templates',
        ];
        foreach ($dirs as $dir) {
            mkdir($this->dir . '/' . $dir, 0775, true);
        }

        foreach (['config.php', 'accounts.json', 'roles.json', 'access.json'] as $file) {
            copy(BASEDIR . '/defaults/' . $file, $this->dir . '/defaults/' . $file);
        }
        copy(BASEDIR . '/defaults/seed/recipes.json', $this->dir . '/defaults/seed/recipes.json');

        foreach (['layout.php', 'home.php', 'recipe.php', 'form.php', 'import.php', 'login.php'] as $file) {
            touch($this->dir . '/templates/' . $file);
        }
        touch($this->dir . '/vendor/autoload.php');
        foreach ([
            'assets/vendor/bootstrap/css/bootstrap.min.css',
            'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
            'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
            'assets/vendor/jquery/jquery.min.js',
            'assets/css/app.css',
        ] as $file) {
            touch($this->dir . '/public/' . $file);
        }
    }

    /**
     * @param array{ok: bool, php: string, groups: list<array{title: string, icon: string, checks: list<array{label: string, detail: string, help: string, ok: bool, required: bool}>}>} $report
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function check(array $report, string $label): array
    {
        foreach ($report['groups'] as $group) {
            foreach ($group['checks'] as $check) {
                if ($check['label'] === $label) {
                    return $check;
                }
            }
        }

        self::fail('No check named ' . $label);
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
