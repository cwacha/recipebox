<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthenticatorDefault;
use App\Auth\AuthorizerDefault;
use App\Auth\JsonFile;
use App\Auth\LayeredStore;
use App\Auth\TokenStore;
use PHPUnit\Framework\TestCase;

/** Gives every test its own temporary "defaults" and "state" directory. */
abstract class AuthTestCase extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-auth-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/defaults', 0775, true);
        mkdir($this->dir . '/state', 0775, true);
    }

    protected function tearDown(): void
    {
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
     */
    protected function writeStandardDefaults(): void
    {
        $account = fn (string $password, string $name): array => ['password' => $password === '!' ? '!' : $this->hash($password), 'fullname' => $name, 'email' => ''];
        $this->writeDefaults('accounts', [
            'admin' => $account('adminpass1', 'Administrator'),
            'ed' => $account('editorpass1', 'Edna Editor'),
            'rd' => $account('readerpass1', 'Rudi Reader'),
            'guest' => $account('!', 'Guest'),
            'locked' => $account('!', 'Locked Out'),
        ]);
        $this->writeDefaults('access', ['admin' => 'all', 'ed' => 'editor', 'rd' => 'reader', 'guest' => 'reader', 'locked' => 'editor']);
        copy(dirname(__DIR__, 2) . '/defaults/roles.json', $this->dir . '/defaults/roles.json');
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
