<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthenticatorDefault;
use App\Auth\AuthorizerDefault;
use App\Auth\TokenStore;
use App\Config\JsonFile;
use App\Config\LayeredStore;
use PHPUnit\Framework\TestCase;

/** Gives every test its own temporary "defaults" and "state" directory. */
abstract class AuthTestCase extends TestCase
{
    protected string $dir;

    /** @var array<string, string|false> original contents of the application's auth state files */
    private array $appStateBackup = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-auth-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/defaults', 0775, true);
        mkdir($this->dir . '/state', 0775, true);
        mkdir($this->dir . '/sessions', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->restoreAppState();
        $this->remove($this->dir);
    }

    /** @param array<string, mixed> $data */
    protected function writeDefaults(string $name, array $data): void
    {
        file_put_contents($this->dir . "/defaults/$name.json", json_encode($data));
    }

    /**
     * Accounts and the access table used by the tests (roles are the real ones from defaults/roles.json):
     * admin (all), ed (editor), rd (reader), guest (reader, cannot sign in), locked (editor, cannot sign in).
     * The application's state directory is used for the real authentication stack; the temporary defaults
     * directory is filled as well for unit tests that build their own stores.
     */
    protected function writeStandardDefaults(): void
    {
        $this->clearAppState();
        $account = fn (string $password, string $name): array => ['password' => $password === '!' ? '!' : $this->hash($password), 'fullname' => $name, 'email' => ''];
        $accounts = [
            'admin' => $account('adminpass1', 'Administrator'),
            'ed' => $account('editorpass1', 'Edna Editor'),
            'rd' => $account('readerpass1', 'Rudi Reader'),
            'guest' => $account('!', 'Guest'),
            'locked' => $account('!', 'Locked Out'),
        ];
        $access = ['admin' => 'all', 'ed' => 'editor', 'rd' => 'reader', 'guest' => 'reader', 'locked' => 'editor'];

        // Unit tests that build their own stores still use the temporary defaults directory.
        $this->writeDefaults('accounts', $accounts);
        $this->writeDefaults('access', $access);

        // The application reads the real defaults and only the local changes in the application's state directory.
        $this->writeAppLocal('accounts', $accounts);
        unset($access['admin'], $access['guest']);
        $this->writeAppLocal('access', $access);
    }

    protected function clearAppState(): void
    {
        $this->backupAppState();
        foreach (array_keys($this->appStateBackup) as $path) {
            $this->removeStateFile($path);
        }
    }

    /** @param array<string, mixed> $data */
    protected function writeAppLocal(string $name, array $data): void
    {
        $this->backupAppState();
        if (!is_dir(STATEDIR)) {
            mkdir(STATEDIR, 0775, true);
        }
        file_put_contents(STATEDIR . '/' . $name . '.json', json_encode($data));
    }

    /** @return array<string, mixed> */
    protected function appLocalFile(string $name): array
    {
        $path = STATEDIR . '/' . $name . '.json';
        if (!is_file($path)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($path), true);
    }

    private function backupAppState(): void
    {
        if ($this->appStateBackup !== []) {
            return;
        }

        foreach (['accounts', 'access', 'roles', 'auth_tokens', 'login_attempts'] as $name) {
            $path = STATEDIR . '/' . $name . '.json';
            $this->appStateBackup[$path] = is_file($path) ? (string) file_get_contents($path) : false;
        }
    }

    private function restoreAppState(): void
    {
        foreach ($this->appStateBackup as $path => $contents) {
            $this->removeStateFile($path);
            if ($contents !== false) {
                file_put_contents($path, $contents);
            }
        }
        $this->appStateBackup = [];
    }

    private function removeStateFile(string $path): void
    {
        @unlink($path);
        @unlink($path . '.lock');
    }

    protected function store(string $name): LayeredStore
    {
        return new LayeredStore(new JsonFile($this->dir . "/defaults/$name.json"), new JsonFile($this->dir . "/state/$name.json"));
    }

    protected function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 4]);
    }

    protected function authenticator(?string $guest = 'guest'): AuthenticatorDefault
    {
        $authenticator = new AuthenticatorDefault(
            $this->store('accounts'),
            $guest,
            new TokenStore(new JsonFile($this->dir . '/state/tokens.json')),
            hashCost: 4,
        );
        $authenticator->init();

        return $authenticator;
    }

    protected function authorizer(): AuthorizerDefault
    {
        $authorizer = new AuthorizerDefault($this->store('roles'), $this->store('access'));
        $authorizer->init();

        return $authorizer;
    }

    /** @return array<string, mixed> */
    protected function localFile(string $name): array
    {
        return (new JsonFile($this->dir . "/state/$name.json"))->read();
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
