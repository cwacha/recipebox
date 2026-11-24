<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthFactory;
use App\Auth\Exception\LockoutPrevented;
use App\Auth\Exception\ValidationFailed;
use App\Auth\SecurityController;
use InvalidArgumentException;

final class SecurityControllerTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeStandardDefaults();
    }

    private function security(bool $enabled = true): SecurityController
    {
        return AuthFactory::create(AuthFactory::normalize([
            'enabled' => $enabled,
            'defaults_dir' => $this->dir . '/defaults',
            'state_dir' => $this->dir . '/state',
            'password_cost' => 4,
        ], dirname(__DIR__, 2)));
    }

    public function testAuthorizationCanBeSwitchedOff(): void
    {
        $off = $this->security(false);
        self::assertTrue($off->hasPermission(null, '/admin'));
        self::assertTrue($off->hasPermission('rd', 'recipe_delete'));

        $on = $this->security();
        self::assertFalse($on->hasPermission(null, '/'));
        self::assertFalse($on->hasPermission('rd', 'recipe_delete'));
        self::assertTrue($on->hasPermission('rd', '/'));
        self::assertTrue($on->hasPermission('admin', 'recipe_delete'));
    }

    public function testAccountDataIsCombinedWithRolesAndPermissions(): void
    {
        $security = $this->security();

        $account = $security->getAccount('ed');
        self::assertSame('Edna Editor', $account['fullname']);
        self::assertSame(['editor'], $account['roles']);
        self::assertContains('recipe_edit', $account['permissions']);
        self::assertNotContains('/admin', $account['permissions']);
        self::assertArrayHasKey('password', $account, 'the contract keeps the stored account shape');

        self::assertNull($security->getAccount('nobody'));

        $public = $security->getPublicAccount('ed');
        self::assertArrayNotHasKey('password', $public);
        self::assertTrue($public['can_sign_in']);
        self::assertFalse($security->getPublicAccount('locked')['can_sign_in']);
        self::assertArrayNotHasKey('password', $security->getAccounts()['admin']);
    }

    public function testNobodyCanBeLockedOutOfTheAdministration(): void
    {
        $security = $this->security();

        // admin is the only account that can manage accounts and roles
        foreach ([
            fn () => $security->saveAccount('admin', [], []),
            fn () => $security->saveAccount('admin', [], ['reader']),
            fn () => $security->deleteAccount('admin'),
            fn () => $security->changePassword('admin', '!'),
        ] as $change) {
            try {
                $change();
                self::fail('the change must be refused');
            } catch (LockoutPrevented) {
            }

            // and it leaves no trace, neither in memory nor on disk
            self::assertSame(['all'], $security->getAccount('admin')['roles']);
            self::assertTrue($security->canSignIn('admin'));
            self::assertSame(['all'], $this->security()->getAccount('admin')['roles']);
        }

        // with a second administrator the first one may be changed
        $security->saveAccount('boss', ['password' => 'boss-password'], ['admin']);
        $security->saveAccount('admin', [], ['reader']);
        self::assertSame(['reader'], $this->security()->getAccount('admin')['roles']);

        // an administrator who cannot sign in does not count
        try {
            $security->changePassword('boss', '!');
            self::fail('boss is the only one who could still administer');
        } catch (LockoutPrevented) {
        }
    }

    public function testRoleChangesThatRemoveTheLastAdministratorAreRefused(): void
    {
        $security = $this->security();
        $security->saveAccount('boss', ['password' => 'boss-password'], ['admin']);
        $security->saveAccount('admin', [], []);

        $this->expectException(LockoutPrevented::class);
        $security->saveRole('admin', ['show', '/']);
    }

    public function testFailedValidationDiscardsTheWholeChange(): void
    {
        $security = $this->security();

        try {
            $security->saveAccount('newbie', ['password' => 'long-enough-password'], ['no-such-role']);
            self::fail('unknown role');
        } catch (ValidationFailed) {
        }

        self::assertNull($security->getAccount('newbie'), 'the account part of the change was rolled back too');
        self::assertNull($this->security()->getAccount('newbie'));
    }

    public function testDeletingAnAccountRemovesItsRolesAndTokens(): void
    {
        $security = $this->security();
        $token = $security->issueToken('ed', 3600);
        self::assertSame('ed', $security->authenticate(['token' => $token]));

        $security->deleteAccount('ed');

        self::assertNull($security->getAccount('ed'));
        self::assertNull($security->authenticate(['token' => $token]));
        self::assertArrayNotHasKey('ed', $security->getAccounts());
        self::assertNotContains('ed', $security->accountsWithRole('editor'));

        $reloaded = $this->security();
        self::assertNull($reloaded->getAccount('ed'));
        self::assertSame(['locked'], $reloaded->accountsWithRole('editor'), 'only the other editor is left');
        self::assertNull($this->localFile('accounts')['ed'], 'the built-in account is remembered as deleted');
        self::assertNull($this->localFile('access')['ed'], 'and so is its role assignment');
    }

    public function testPasswordHelpers(): void
    {
        $security = $this->security();

        self::assertTrue($security->verifyPassword('ed', 'editorpass1'));
        self::assertFalse($security->verifyPassword('ed', 'nope'));
        self::assertFalse($security->verifyPassword('locked', '!'));

        $before = $security->passwordVersion('ed');
        $security->changePassword('ed', 'another-password');
        self::assertNotSame($before, $security->passwordVersion('ed'));
        self::assertTrue($security->verifyPassword('ed', 'another-password'));
        self::assertNull($security->passwordVersion('nobody'));
    }

    public function testTheDefaultAdministratorPasswordIsRecognised(): void
    {
        $security = AuthFactory::create(AuthFactory::normalize([
            'defaults_dir' => dirname(__DIR__, 2) . '/defaults',
            'state_dir' => $this->dir . '/state',
            'password_cost' => 4,
        ], dirname(__DIR__, 2)));

        self::assertTrue($security->usesDefaultPassword('admin'));
        $security->changePassword('admin', 'a-better-password');
        self::assertFalse($security->usesDefaultPassword('admin'));
        self::assertFalse($security->usesDefaultPassword('ed'));
    }

    public function testProvidersAreChosenFromARegistryOnly(): void
    {
        $config = fn (array $extra): array => AuthFactory::normalize($extra + ['state_dir' => $this->dir . '/state', 'defaults_dir' => $this->dir . '/defaults'], dirname(__DIR__, 2));

        try {
            AuthFactory::create($config(['authenticator' => '../../etc/passwd']));
            self::fail('unknown provider names are refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown provider', $e->getMessage());
        }

        try {
            AuthFactory::create($config(['authenticators' => ['evil' => fn () => new \stdClass()], 'authenticator' => 'evil']));
            self::fail('a provider must implement the contract');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('must implement', $e->getMessage());
        }

        $custom = $this->authenticator();
        $security = AuthFactory::create($config(['authenticators' => ['mine' => fn () => $custom], 'authenticator' => 'mine']));
        self::assertSame('ed', $security->authenticate(['login_user' => 'ed', 'login_password' => 'editorpass1']));
    }
}
