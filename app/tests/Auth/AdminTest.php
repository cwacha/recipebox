<?php

declare(strict_types=1);

namespace Tests\Auth;

final class AdminTest extends AuthWebTestCase
{
    private Browser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->loggedIn('admin', 'adminpass1');
    }

    /** @return array<string, mixed> accounts as stored on disk (local changes only) */
    private function localAccounts(): array
    {
        return $this->appLocalFile('accounts');
    }

    public function testAllAdminViewsShowTheirContent(): void
    {
        $overview = $this->admin->get('/admin')->body();
        self::assertStringContainsString('Administration', $overview);
        self::assertStringContainsString('5</div>', $overview, 'account count');
        self::assertStringNotContainsString('still uses the default password', $overview);
        self::assertStringContainsString('Version ' . trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION')), $overview);

        $accounts = $this->admin->get('/admin/accounts')->body();
        foreach (['Administrator', 'Edna Editor', 'Rudi Reader', 'Locked Out'] as $name) {
            self::assertStringContainsString($name, $accounts);
        }
        self::assertStringContainsString('<span class="tag">editor</span>', $accounts);
        self::assertStringContainsString('disabled', $accounts, 'accounts without a password are marked');

        $account = $this->admin->get('/admin/account', ['id' => 'ed'])->body();
        self::assertStringContainsString('Edna Editor', $account);
        self::assertStringContainsString('recipe_edit', $account, 'effective permissions are listed');
        self::assertStringNotContainsString('/admin/roles', $account, 'editors have no admin views');

        $roles = $this->admin->get('/admin/roles')->body();
        foreach (['all', 'editor', 'reader', '/admin/role/edit', 'recipe_delete'] as $text) {
            self::assertStringContainsString($text, $roles);
        }

        self::assertStringContainsString('New account', $this->admin->get('/admin/account/edit')->body());
        self::assertStringContainsString('Edit account', $this->admin->get('/admin/account/edit', ['id' => 'ed'])->body());
        self::assertStringContainsString('Set password', $this->admin->get('/admin/account/password', ['id' => 'ed'])->body());
        self::assertStringContainsString('New role', $this->admin->get('/admin/role/edit')->body());
        self::assertStringContainsString('Edit role', $this->admin->get('/admin/role/edit', ['id' => 'editor'])->body());
    }

    public function testUnknownAccountsAndRolesGiveANotFoundPage(): void
    {
        foreach ([['/admin/account', 'nobody'], ['/admin/account/edit', 'nobody'], ['/admin/account/password', 'nobody']] as [$path, $id]) {
            self::assertSame(404, $this->admin->get($path, ['id' => $id])->status(), $path);
        }
        self::assertSame(404, $this->admin->get('/admin/account')->status(), 'no id');
        self::assertSame(404, $this->admin->get('/admin/role/edit', ['id' => 'ghost'])->status());
    }

    public function testEverythingFromTheDatabaseIsEscaped(): void
    {
        $this->admin->post('/admin/account/edit', ['mode' => 'new', 'id' => 'xss', 'fullname' => '<script>alert(1)</script>', 'email' => '', 'no_password' => '1']);

        foreach (['/admin/accounts', '/admin/account'] as $path) {
            $body = $this->admin->get($path, ['id' => 'xss'])->body();
            self::assertStringNotContainsString('<script>alert(1)', $body, $path);
            self::assertStringContainsString('&lt;script&gt;', $body, $path);
        }
    }

    public function testCreatingAnAccountWithRoles(): void
    {
        $response = $this->admin->post('/admin/account/edit', [
            'mode' => 'new', 'id' => 'Newbie', 'fullname' => 'New Bie', 'email' => 'newbie@example.com',
            'password' => 'newbie-password', 'confirm_password' => 'newbie-password', 'roles' => ['editor', 'reader'],
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/account?id=newbie', Browser::location($response));
        self::assertStringContainsString('has been saved', $this->flashMessages($this->admin));

        $stored = $this->localAccounts()['newbie'];
        self::assertStringStartsWith('$2y$', $stored['password']);
        self::assertStringNotContainsString('newbie-password', (string) file_get_contents(STATEDIR . '/accounts.json'));
        self::assertSame('editor,reader', $this->appLocalFile('access')['newbie']);

        $newbie = $this->loggedIn('newbie', 'newbie-password');
        self::assertSame(200, $newbie->get('/recipes/new')->status());
        self::assertSame(302, $newbie->get('/admin')->status());
    }

    public function testAccountFormValidation(): void
    {
        $valid = ['mode' => 'new', 'id' => 'valid', 'fullname' => '', 'email' => '', 'password' => 'long-enough-1', 'confirm_password' => 'long-enough-1'];

        foreach ([
            'duplicate id' => [['id' => 'ed'], 'already exists'],
            'bad id' => [['id' => '1 bad'], 'lowercase letters'],
            'short password' => [['password' => 'short', 'confirm_password' => 'short'], 'at least 8'],
            'different passwords' => [['confirm_password' => 'something-else'], 'not the same'],
            'bad email' => [['email' => 'nope'], 'valid email'],
            'unknown role' => [['roles' => ['ghost']], 'Unknown role'],
        ] as $case => [$change, $message]) {
            $response = $this->admin->post('/admin/account/edit', $change + $valid);
            self::assertSame(422, $response->status(), $case);
            self::assertStringContainsString($message, $response->body(), $case);
            self::assertStringContainsString('<form', $response->body(), 'the form is shown again');
        }
        self::assertNull($this->localAccounts()['valid'] ?? null);
        self::assertNull($this->appLocalFile('access')['valid'] ?? null);

        $filled = $this->admin->post('/admin/account/edit', ['fullname' => 'Keep Me', 'email' => 'bad'] + $valid);
        self::assertStringContainsString('value="Keep Me"', $filled->body(), 'entered values are not lost');
    }

    public function testEditingAnAccountKeepsItsPasswordAndReplacesItsRoles(): void
    {
        $response = $this->admin->post('/admin/account/edit', ['mode' => 'edit', 'id' => 'ed', 'fullname' => 'Edna E.', 'email' => 'edna@example.com', 'roles' => ['reader']]);

        self::assertSame(303, $response->status());
        $editor = $this->loggedIn('ed', 'editorpass1');
        self::assertSame(302, $editor->get('/recipes/new')->status(), 'her editor role is gone');
        self::assertSame(200, $editor->get('/')->status());
        self::assertStringContainsString('Edna E.', $this->admin->get('/admin/account', ['id' => 'ed'])->body());
        self::assertSame(404, $this->admin->post('/admin/account/edit', ['mode' => 'edit', 'id' => 'ghost'])->status());
    }

    public function testChangingAndDisablingPasswords(): void
    {
        $mismatch = $this->admin->post('/admin/account/password', ['id' => 'rd', 'new_password' => 'another-password', 'confirm_password' => 'different']);
        self::assertSame(422, $mismatch->status());
        $short = $this->admin->post('/admin/account/password', ['id' => 'rd', 'new_password' => 'short', 'confirm_password' => 'short']);
        self::assertSame(422, $short->status());
        self::assertStringContainsString('at least 8', $short->body());
        self::assertSame(303, $this->browser()->login('rd', 'readerpass1')->status(), 'the old password still works');

        self::assertSame(303, $this->admin->post('/admin/account/password', ['id' => 'rd', 'new_password' => 'another-password', 'confirm_password' => 'another-password'])->status());
        self::assertSame(401, $this->browser()->login('rd', 'readerpass1')->status());
        self::assertSame(303, $this->browser()->login('rd', 'another-password')->status());

        self::assertSame(303, $this->admin->post('/admin/account/password', ['id' => 'rd', 'disable' => '1'])->status());
        self::assertSame(401, $this->browser()->login('rd', 'another-password')->status());
        self::assertSame('!', $this->localAccounts()['rd']['password']);
    }

    public function testAnAdministratorKeepsHerSessionWhenChangingHerOwnPasswordButCannotDisableIt(): void
    {
        $change = $this->admin->post('/admin/account/password', ['id' => 'admin', 'new_password' => 'my-new-admin-pass', 'confirm_password' => 'my-new-admin-pass']);
        self::assertSame(303, $change->status());
        self::assertSame(200, $this->admin->get('/admin')->status(), 'still signed in');

        $disable = $this->admin->post('/admin/account/password', ['id' => 'admin', 'disable' => '1']);
        self::assertSame(422, $disable->status());
        self::assertStringContainsString('cannot disable your own', $disable->body());
    }

    public function testDeletingAccounts(): void
    {
        $self = $this->admin->post('/admin/account/delete', ['id' => 'admin']);
        self::assertSame(303, $self->status());
        self::assertStringContainsString('cannot delete the account you are signed in with', $this->flashMessages($this->admin));
        self::assertSame(200, $this->admin->get('/admin/account', ['id' => 'admin'])->status(), 'the account is still there');

        $deleted = $this->admin->post('/admin/account/delete', ['id' => 'rd']);
        self::assertSame('/admin/accounts', Browser::location($deleted));
        self::assertSame(404, $this->admin->get('/admin/account', ['id' => 'rd'])->status());
        self::assertSame(401, $this->browser()->login('rd', 'readerpass1')->status());
        self::assertSame(404, $this->admin->post('/admin/account/delete', ['id' => 'rd'])->status());
    }

    public function testTheLastAdministratorCannotBeLockedOut(): void
    {
        $response = $this->admin->post('/admin/account/edit', ['mode' => 'edit', 'id' => 'admin', 'fullname' => 'Administrator', 'email' => '', 'roles' => []]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('no account could manage accounts and roles anymore', $response->body());
        self::assertSame(200, $this->admin->get('/admin/roles')->status(), 'still an administrator');
        self::assertArrayNotHasKey('admin', $this->appLocalFile('access'), 'nothing was saved');
    }

    public function testCreatingEditingAndDeletingRoles(): void
    {
        $created = $this->admin->post('/admin/role/edit', ['mode' => 'new', 'id' => 'Chef', 'permissions' => ['show', '/', '/recipes'], 'extra' => "/kitchen, kitchen_order\nrecipe_edit"]);
        self::assertSame(303, $created->status());
        self::assertSame('/admin/roles', Browser::location($created));

        $roles = $this->admin->get('/admin/roles')->body();
        self::assertStringContainsString('/kitchen', $roles);
        self::assertStringContainsString('kitchen_order', $roles);
        self::assertSame('show,/,/recipes,/kitchen,kitchen_order,recipe_edit', $this->appLocalFile('roles')['chef']);
        self::assertStringContainsString('kitchen_order', $this->admin->get('/admin/role/edit', ['id' => 'all'])->body(), 'the role all learns about new permissions');

        // give it to an account and use it
        $this->admin->post('/admin/account/edit', ['mode' => 'edit', 'id' => 'rd', 'fullname' => '', 'email' => '', 'roles' => ['chef']]);
        $reader = $this->loggedIn('rd', 'readerpass1');
        self::assertSame(200, $reader->json('PUT', '/api/recipes/' . $this->recipeId, $this->recipePayload('Edited by chef'))->status());

        // edit: take recipe_edit away again, and the custom permission is removable
        $this->admin->post('/admin/role/edit', ['mode' => 'edit', 'id' => 'chef', 'permissions' => ['show', '/']]);
        self::assertSame(403, $reader->json('PUT', '/api/recipes/' . $this->recipeId, $this->recipePayload())->status());
        self::assertStringNotContainsString('kitchen_order', $this->admin->get('/admin/role/edit', ['id' => 'all'])->body());

        // delete: the role disappears from the account
        $deleted = $this->admin->post('/admin/role/delete', ['id' => 'chef']);
        self::assertSame(303, $deleted->status());
        self::assertSame(404, $this->admin->get('/admin/role/edit', ['id' => 'chef'])->status());
        $account = $this->admin->get('/admin/account', ['id' => 'rd']);
        self::assertSame(200, $account->status());
        self::assertStringNotContainsString('chef', $account->body(), 'the deleted role was taken away from the account');
        self::assertNull($this->appLocalFile('access')['rd'] ?? null, 'rd has no role left');
    }

    public function testRoleFormValidation(): void
    {
        foreach ([
            'duplicate' => [['mode' => 'new', 'id' => 'editor', 'permissions' => ['show']], 'already exists'],
            'bad id' => [['mode' => 'new', 'id' => 'Bad Id!', 'permissions' => ['show']], 'lowercase letters'],
            'nothing selected' => [['mode' => 'new', 'id' => 'empty'], 'at least one permission'],
            'invalid permission' => [['mode' => 'new', 'id' => 'odd', 'permissions' => ['show'], 'extra' => 'no/leading/slash'], 'Not a valid permission'],
            'the role all' => [['mode' => 'new', 'id' => 'all', 'permissions' => ['show']], 'already exists'],
        ] as $case => [$post, $message]) {
            $response = $this->admin->post('/admin/role/edit', $post);
            self::assertSame(422, $response->status(), $case);
            self::assertStringContainsString($message, $response->body(), $case);
        }

        $editAll = $this->admin->post('/admin/role/edit', ['mode' => 'edit', 'id' => 'all', 'permissions' => ['show']]);
        self::assertSame(422, $editAll->status());
        self::assertStringContainsString('maintained automatically', $editAll->body());

        self::assertSame(404, $this->admin->post('/admin/role/edit', ['mode' => 'edit', 'id' => 'ghost', 'permissions' => ['show']])->status());
        self::assertStringContainsString('cannot be changed or deleted', $this->admin->get('/admin/role/edit', ['id' => 'all'])->body());
    }

    public function testBuiltInRolesCanBeRemovedButTheRoleAllCannot(): void
    {
        $all = $this->admin->post('/admin/role/delete', ['id' => 'all']);
        self::assertSame(303, $all->status());
        self::assertStringContainsString('maintained automatically', $this->flashMessages($this->admin));
        self::assertStringContainsString('<code>all</code>', $this->admin->get('/admin/roles')->body());

        $this->admin->post('/admin/role/delete', ['id' => 'reader']);
        self::assertStringNotContainsString('<code>reader</code>', $this->admin->get('/admin/roles')->body());
        self::assertNull($this->appLocalFile('roles')['reader'], 'a removed built-in role is remembered as removed, the defaults file is untouched');
        self::assertStringContainsString('reader', (string) file_get_contents(DEFAULTSDIR . '/roles.json'));
    }

    public function testOnlyAccountsWithTheActionPermissionCanChangeThings(): void
    {
        // an account that may open the forms but has no action permission
        $this->admin->post('/admin/role/edit', ['mode' => 'new', 'id' => 'viewer', 'permissions' => ['show', '/', '/admin', '/admin/accounts', '/admin/account', '/admin/account/edit', '/admin/roles', '/admin/role/edit']]);
        $this->admin->post('/admin/account/edit', ['mode' => 'new', 'id' => 'looker', 'password' => 'looker-password', 'confirm_password' => 'looker-password', 'roles' => ['viewer']]);
        $looker = $this->loggedIn('looker', 'looker-password');

        self::assertSame(200, $looker->get('/admin/account/edit', ['id' => 'ed'])->status());
        self::assertSame(302, $looker->post('/admin/account/edit', ['mode' => 'edit', 'id' => 'ed', 'roles' => []])->status());
        self::assertSame(302, $looker->post('/admin/account/delete', ['id' => 'ed'])->status());
        self::assertSame(302, $looker->post('/admin/role/delete', ['id' => 'editor'])->status());
        self::assertSame(302, $looker->post('/admin/role/edit', ['mode' => 'edit', 'id' => 'editor', 'permissions' => ['show']])->status());
        self::assertSame(302, $looker->post('/admin/account/password', ['id' => 'admin', 'new_password' => 'hijacked-password', 'confirm_password' => 'hijacked-password'])->status());

        self::assertStringContainsString('Edna Editor', $this->admin->get('/admin/accounts')->body());
        self::assertSame(401, $this->browser()->login('admin', 'hijacked-password')->status());
        self::assertSame(303, $this->browser()->login('admin', 'adminpass1')->status());
    }

    public function testChangingTheOwnPassword(): void
    {
        $editor = $this->loggedIn('ed', 'editorpass1');
        self::assertStringContainsString('Change password', $editor->get('/account/password')->body());

        foreach ([
            'wrong current' => [['current_password' => 'nope'], 'not correct'],
            'different' => [['confirm_password' => 'something-else'], 'not the same'],
            'too short' => [['new_password' => 'short', 'confirm_password' => 'short'], 'at least 8'],
        ] as $case => [$change, $message]) {
            $response = $editor->post('/account/password', $change + ['current_password' => 'editorpass1', 'new_password' => 'a-new-password', 'confirm_password' => 'a-new-password']);
            self::assertSame(422, $response->status(), $case);
            self::assertStringContainsString($message, $response->body(), $case);
        }
        self::assertSame(303, $this->browser()->login('ed', 'editorpass1')->status());

        $done = $editor->post('/account/password', ['current_password' => 'editorpass1', 'new_password' => 'a-new-password', 'confirm_password' => 'a-new-password']);
        self::assertSame(303, $done->status());
        self::assertSame(200, $editor->get('/recipes/new')->status(), 'the own session survives');
        self::assertSame(401, $this->browser()->login('ed', 'editorpass1')->status());
        self::assertSame(303, $this->browser()->login('ed', 'a-new-password')->status());
    }

    public function testTheOwnPasswordIsAlwaysTheOneOfTheSessionNotOfASubmittedId(): void
    {
        $editor = $this->loggedIn('ed', 'editorpass1');

        $editor->post('/account/password', ['id' => 'admin', 'account' => 'admin', 'current_password' => 'editorpass1', 'new_password' => 'a-new-password', 'confirm_password' => 'a-new-password']);

        self::assertSame(303, $this->browser()->login('admin', 'adminpass1')->status(), 'the administrator was not touched');
        self::assertSame(303, $this->browser()->login('ed', 'a-new-password')->status());
    }

    public function testGuestsCannotChangeAPassword(): void
    {
        $guest = $this->browser();

        self::assertSame(302, $guest->get('/account/password')->status());
        self::assertSame(302, $guest->post('/account/password', ['current_password' => '!', 'new_password' => 'a-new-password', 'confirm_password' => 'a-new-password'])->status());
    }
}
