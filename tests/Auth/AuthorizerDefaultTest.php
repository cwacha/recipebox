<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Exception\ValidationFailed;

final class AuthorizerDefaultTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaults('roles', [
            'all' => 'ALL,show,/,/admin,recipe_edit,recipe_delete',
            'reader' => 'show,/',
            'editor' => 'show,/,recipe_edit',
            'cook' => 'recipe_edit,/kitchen',
        ]);
        $this->writeDefaults('access', ['admin' => 'all', 'rita' => 'reader', 'ed' => 'editor,cook']);
    }

    public function testNoAccountIsDeniedEverything(): void
    {
        $auth = $this->authorizer();

        foreach ([null, '', 'show', 'login', '/login', 'logout', '/', 'recipe_edit'] as $permission) {
            self::assertFalse($auth->authorize(null, $permission), (string) $permission);
        }
    }

    public function testIdentifiedAccountsAlwaysMayUseTheImplicitActions(): void
    {
        $auth = $this->authorizer();

        foreach ([null, '', 'show', 'login', '/login', 'logout', '/logout'] as $permission) {
            self::assertTrue($auth->authorize('nobody-with-roles', $permission), (string) $permission);
        }
        self::assertFalse($auth->authorize('nobody-with-roles', '/admin'));
        self::assertFalse($auth->authorize('nobody-with-roles', 'recipe_edit'));
    }

    public function testPermissionsAreCombinedOverRolesWithoutDuplicates(): void
    {
        $auth = $this->authorizer();

        self::assertSame(['show', '/', 'recipe_edit', '/kitchen'], $auth->getPermissions('ed'));
        self::assertTrue($auth->authorize('ed', '/kitchen'));
        self::assertTrue($auth->authorize('ed', 'recipe_edit'));
        self::assertFalse($auth->authorize('ed', 'recipe_delete'));
        self::assertSame(['editor', 'cook'], $auth->getRoles('ed'));
        self::assertSame([], $auth->getRoles('nobody'));
    }

    public function testTheExplicitAllPermissionAuthorizesEverything(): void
    {
        $auth = $this->authorizer();

        self::assertTrue($auth->authorize('admin', 'anything_at_all'));
        self::assertTrue($auth->authorize('admin', '/never/heard/of'));
        self::assertFalse($auth->authorize('rita', 'anything_at_all'));
    }

    public function testRoleAllIsMaintainedAutomaticallyAndProtected(): void
    {
        $auth = $this->authorizer();

        $auth->setRolePermissions('reports', ['show', '/reports', 'report_export']);
        self::assertContains('/reports', $auth->getRoles()['all']);
        self::assertContains('report_export', $auth->getRoles()['all']);

        $auth->setRolePermissions('reports', ['show']);
        self::assertNotContains('/reports', $auth->getRoles()['all'], 'permissions nobody mentions anymore disappear from "all"');
        self::assertContains('/admin', $auth->getRoles()['all'], 'built-in permissions stay');

        foreach ([['all', ['show']], ['all', null], ['ALL', ['show']]] as [$id, $permissions]) {
            try {
                $auth->setRolePermissions($id, $permissions);
                self::fail('the role all must not be changeable');
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('id', $e->errors);
            }
        }
        self::assertSame('all', array_key_first($auth->getRoles()), 'the role all always comes first');
    }

    public function testAssignmentsAndRolesAreValidated(): void
    {
        $auth = $this->authorizer();

        try {
            $auth->setRoles('rita', ['reader', 'ghost']);
            self::fail('unknown role');
        } catch (ValidationFailed $e) {
            self::assertStringContainsString('ghost', $e->errors['roles']);
        }
        self::assertSame(['reader'], $auth->getRoles('rita'), 'a rejected change leaves everything as it was');

        foreach (['nope', '/with space', '', 'a,b', '/x/../y;', str_repeat('p', 81)] as $bad) {
            if ($bad === '' || $bad === 'a,b') {
                continue; // empty items are dropped, commas split
            }
            try {
                $auth->setRolePermissions('x', [$bad === 'nope' ? '9nope' : $bad]);
                self::fail("$bad must be rejected");
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('permissions', $e->errors);
            }
        }
        self::assertTrue($auth::isValidPermission('/admin/role/edit'));
        self::assertTrue($auth::isValidPermission('category_delete'));
        self::assertTrue($auth::isValidPermission('ALL'));
        self::assertFalse($auth::isValidPermission('admin/roles'));
    }

    public function testAssignmentsCanBeReplacedAndRemoved(): void
    {
        $auth = $this->authorizer();

        $auth->setRoles('rita', ['editor']);
        self::assertSame(['editor'], $auth->getRoles('rita'));
        $auth->setRoles('rita', []);
        self::assertSame([], $auth->getRoles('rita'));
        $auth->setRoles('newbie', ['reader', 'reader']);
        self::assertSame(['reader'], $auth->getRoles('newbie'));
        $auth->setRoles('ed', null);
        self::assertFalse($auth->authorize('ed', 'recipe_edit'));
    }

    public function testDeletingARoleRemovesItFromAllAccounts(): void
    {
        $auth = $this->authorizer();

        $auth->setRolePermissions('cook', null);

        self::assertArrayNotHasKey('cook', $auth->getRoles());
        self::assertSame(['editor'], $auth->getRoles('ed'));
        self::assertNotContains('/kitchen', $auth->getRoles()['all']);

        $auth->setRolePermissions('reader', null);
        self::assertSame([], $auth->getRoles('rita'), 'an account without any role is removed from the access table');
    }

    public function testChangesPersistAsDifferencesToTheDefaults(): void
    {
        $auth = $this->authorizer();
        $auth->setRolePermissions('chef', ['show', '/kitchen']);
        $auth->setRolePermissions('cook', null);
        $auth->setRoles('rita', ['reader', 'chef']);
        $auth->saveRoles();
        $auth->saveAccess();

        $reloaded = $this->authorizer();
        self::assertSame(['show', '/kitchen'], $reloaded->getRoles()['chef']);
        self::assertArrayNotHasKey('cook', $reloaded->getRoles());
        self::assertSame(['reader', 'chef'], $reloaded->getRoles('rita'));

        self::assertSame(['chef' => 'show,/kitchen', 'cook' => null], $this->localFile('roles'));
        self::assertArrayNotHasKey('admin', $this->localFile('access'), 'unchanged defaults are not copied');
    }
}
