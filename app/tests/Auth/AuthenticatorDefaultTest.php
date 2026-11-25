<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Exception\ValidationFailed;

final class AuthenticatorDefaultTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaults('accounts', [
            'admin' => ['password' => $this->hash('secret-admin'), 'fullname' => 'Administrator', 'email' => ''],
            'bob' => ['password' => $this->hash('secret-bob'), 'fullname' => 'Bob', 'email' => 'bob@example.com'],
            'guest' => ['password' => '!', 'fullname' => 'Guest', 'email' => ''],
        ]);
    }

    public function testChecksPasswordsFromTheLoginFormAndBasicAuth(): void
    {
        $auth = $this->authenticator();

        self::assertSame('admin', $auth->authenticate(['login_user' => 'admin', 'login_password' => 'secret-admin']));
        self::assertSame('admin', $auth->authenticate(['basic_user' => 'Admin', 'basic_password' => 'secret-admin']), 'account IDs are case insensitive');
        self::assertNull($auth->authenticate(['login_user' => 'admin', 'login_password' => 'wrong']));
        self::assertNull($auth->authenticate(['login_user' => 'nobody', 'login_password' => 'secret-admin']));
        self::assertNull($auth->authenticate([]));
    }

    public function testKnowingTheStoredHashIsNotEnoughToSignIn(): void
    {
        $auth = $this->authenticator();
        $hash = $auth->getAccount('admin')['password'];

        self::assertNull($auth->authenticate(['login_user' => 'admin', 'login_password' => $hash]));
        self::assertNull($auth->authenticate(['basic_user' => 'admin', 'basic_password' => $hash]));

        // and setting an account from a read-back copy does not double hash it either
        $auth->setAccount('admin', $auth->getAccount('admin'));
        self::assertSame($hash, $auth->getAccount('admin')['password']);
        self::assertSame('admin', $auth->authenticate(['login_user' => 'admin', 'login_password' => 'secret-admin']));
    }

    public function testMalformedAndConflictingCredentialsFailClosed(): void
    {
        $auth = $this->authenticator();
        $admin = ['login_user' => 'admin', 'login_password' => 'secret-admin'];
        $bob = ['basic_user' => 'bob', 'basic_password' => 'secret-bob'];

        self::assertNull($auth->authenticate($admin + $bob), 'two different accounts');
        self::assertNull($auth->authenticate($admin + ['basic_user' => 'admin', 'basic_password' => 'wrong']), 'one source is wrong');
        self::assertSame('admin', $auth->authenticate($admin + ['basic_user' => 'admin', 'basic_password' => 'secret-admin']), 'sources agree');

        self::assertNull($auth->authenticate(['login_user' => 'admin']), 'password missing');
        self::assertNull($auth->authenticate(['login_user' => 'admin', 'login_password' => '']));
        self::assertNull($auth->authenticate(['login_user' => ['admin'], 'login_password' => 'secret-admin']), 'not a string');
        self::assertNull($auth->authenticate(['login_user' => "ad\0min", 'login_password' => 'secret-admin']));
        self::assertSame('admin', $auth->authenticate($admin + ['guest' => true]), 'valid credentials win over the guest flag');
        self::assertNull($auth->authenticate(['login_user' => 'admin', 'login_password' => 'wrong', 'guest' => true]), 'wrong credentials never fall back to the guest');
    }

    public function testDisabledPasswordNeverAuthenticates(): void
    {
        $auth = $this->authenticator();

        self::assertNull($auth->authenticate(['login_user' => 'guest', 'login_password' => '!']));
        self::assertNull($auth->authenticate(['login_user' => 'guest', 'login_password' => 'guest']));
        self::assertNull($auth->authenticate(['login_user' => 'guest', 'login_password' => '']));
    }

    public function testGuestIsOnlyAvailableWhenEnabled(): void
    {
        self::assertSame('guest', $this->authenticator('guest')->authenticate(['guest' => true]));
        self::assertNull($this->authenticator(null)->authenticate(['guest' => true]));
        self::assertNull($this->authenticator('')->authenticate(['guest' => true]));
        self::assertNull($this->authenticator('missing')->authenticate(['guest' => true]), 'configured guest account does not exist');
        self::assertNull($this->authenticator('guest')->authenticate(['guest' => false]));
    }

    public function testPasswordsAreHashedExactlyOnceAndKeptWhenNotGiven(): void
    {
        $auth = $this->authenticator();

        $auth->setAccount('Carol', ['password' => 'carols-password', 'fullname' => ' Carol ', 'email' => 'carol@example.com']);
        $stored = $auth->getAccount('carol');
        self::assertSame('Carol', $stored['fullname']);
        self::assertNotSame('carols-password', $stored['password']);
        self::assertTrue(password_verify('carols-password', $stored['password']));

        // a value that already is a hash is stored as it is, not hashed again
        $auth->setAccount('dave', ['password' => $stored['password']]);
        self::assertSame($stored['password'], $auth->getAccount('dave')['password']);

        // updating other fields keeps the password
        $auth->setAccount('carol', ['fullname' => 'Carol C.']);
        self::assertSame($stored['password'], $auth->getAccount('carol')['password']);
        self::assertSame('Carol C.', $auth->getAccount('carol')['fullname']);

        // no password given for a new account means it cannot sign in
        $auth->setAccount('erin', ['fullname' => 'Erin']);
        self::assertSame('!', $auth->getAccount('erin')['password']);
    }

    public function testRejectsInvalidAccountData(): void
    {
        $auth = $this->authenticator();

        foreach ([
            ['x', ['password' => 'short'], 'password'],
            ['x', ['password' => str_repeat('a', 73)], 'password'],
            ['x', ['email' => 'not-an-email'], 'email'],
            ['x', ['fullname' => str_repeat('n', 101)], 'fullname'],
            ['9starts-with-digit', [], 'id'],
            ['has space', [], 'id'],
            ['', [], 'id'],
        ] as [$id, $data, $field]) {
            try {
                $auth->setAccount($id, $data);
                self::fail("$id " . json_encode($data) . ' should be rejected');
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
        self::assertNull($auth->getAccount('x'));
    }

    public function testChangesAreLocalAndSurviveReload(): void
    {
        $auth = $this->authenticator();
        $auth->setAccount('carol', ['password' => 'carols-password']);
        $auth->setAccount('bob', ['fullname' => 'Robert']);
        $auth->setAccount('guest', null);
        $auth->saveAccounts();

        $reloaded = $this->authenticator();
        self::assertSame('carol', $reloaded->authenticate(['login_user' => 'carol', 'login_password' => 'carols-password']));
        self::assertSame('Robert', $reloaded->getAccount('bob')['fullname']);
        self::assertNull($reloaded->getAccount('guest'));
        self::assertSame(['admin', 'bob', 'carol'], array_keys($reloaded->getAccounts()));

        // defaults are not copied into the local file
        self::assertArrayNotHasKey('admin', $this->localFile('accounts'));
        self::assertNull($this->localFile('accounts')['guest'], 'a removed default is remembered as removed');
    }

    public function testPersistentLoginTokensExpireAndCanBeRevoked(): void
    {
        $auth = $this->authenticator();

        $token = $auth->issueToken('bob', 3600);
        self::assertMatchesRegularExpression('/^[a-f0-9]{18}\.[a-f0-9]{64}$/', $token);
        self::assertSame('bob', $auth->authenticate(['token' => $token]));
        self::assertStringNotContainsString('secret-bob', (string) file_get_contents($this->dir . '/state/tokens.json'));
        self::assertStringNotContainsString(explode('.', $token)[1], (string) file_get_contents($this->dir . '/state/tokens.json'), 'only a hash of the secret is stored');

        $auth->revokeToken($token);
        self::assertNull($auth->authenticate(['token' => $token]));

        $expired = $auth->issueToken('bob', -10);
        self::assertNull($auth->authenticate(['token' => $expired]));

        $one = $auth->issueToken('bob', 3600);
        $two = $auth->issueToken('bob', 3600);
        $other = $auth->issueToken('admin', 3600);
        $auth->revokeTokens('bob');
        self::assertNull($auth->authenticate(['token' => $one]));
        self::assertNull($auth->authenticate(['token' => $two]));
        self::assertSame('admin', $auth->authenticate(['token' => $other]));
    }

    public function testTokensDoNotWorkForDisabledOrDeletedAccountsOrWhenGuessed(): void
    {
        $auth = $this->authenticator();
        $token = $auth->issueToken('bob', 3600);

        [$selector] = explode('.', $token);
        self::assertNull($auth->authenticate(['token' => $selector . '.' . str_repeat('0', 64)]), 'right selector, wrong secret');
        self::assertNull($auth->authenticate(['token' => $token]), 'a guessed secret burns the token');

        $token = $auth->issueToken('bob', 3600);
        $auth->setAccount('bob', ['password' => '!']);
        self::assertNull($auth->authenticate(['token' => $token]));

        self::assertNull($auth->authenticate(['token' => 'garbage']));
        self::assertNull($auth->authenticate(['token' => ['x']]));
    }
}
