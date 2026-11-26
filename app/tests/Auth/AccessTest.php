<?php

declare(strict_types=1);

namespace Tests\Auth;

final class AccessTest extends AuthWebTestCase
{
    public function testAnAccountWithoutPermissionGetsAnErrorAndLandsOnTheImplicitShowPage(): void
    {
        $editor = $this->loggedIn('ed', 'editorpass1');

        foreach (['/admin', '/admin/accounts', '/admin/account/edit', '/admin/roles', '/admin/role/edit'] as $page) {
            $response = $editor->get($page);
            self::assertSame(302, $response->status(), $page);
            self::assertSame('/', Browser::location($response), $page);
            self::assertStringContainsString('do not have permission', $this->flashMessages($editor));
        }
        self::assertSame(200, $editor->get('/')->status(), 'the recipe list is where denied requests end up');
    }

    public function testAnAccountThatMayNotEvenShowTheListGetsAnErrorPageInsteadOfALoop(): void
    {
        $security = \App\Auth\AuthFactory::create(\App\Auth\AuthFactory::normalize([
            'password_cost' => 4,
        ]));
        $security->saveRole('nolist', ['/account/password']);
        $security->saveAccount('nolist', ['password' => 'nolist-password'], ['nolist']);

        $browser = $this->browser(app: $this->app());
        self::assertSame(303, $browser->login('nolist', 'nolist-password')->status());

        $home = $browser->get('/');
        self::assertSame(403, $home->status());
        self::assertStringContainsString('Not allowed', $home->body());
    }

    public function testEachRoleOnlyReachesWhatItsPermissionsAllow(): void
    {
        $reader = $this->loggedIn('rd', 'readerpass1');
        self::assertSame(200, $reader->get('/')->status());
        self::assertSame(200, $reader->get('/recipes/' . $this->recipeSlug)->status());
        self::assertSame(302, $reader->get('/recipes/new')->status());
        self::assertSame(302, $reader->get('/recipes/' . $this->recipeSlug . '/edit')->status());
        self::assertSame(302, $reader->get('/import')->status());
        self::assertSame(302, $reader->get('/account/password')->status(), 'readers cannot change a password, they have none to protect');

        $editor = $this->loggedIn('ed', 'editorpass1');
        foreach (['/recipes/new', '/recipes/' . $this->recipeSlug . '/edit', '/import', '/account/password'] as $page) {
            self::assertSame(200, $editor->get($page)->status(), $page);
        }
        $recipe = $editor->get('/recipes/' . $this->recipeSlug)->body();
        self::assertStringContainsString('btnDelete', $recipe);
        self::assertStringContainsString('/edit', $recipe);

        $admin = $this->loggedIn('admin', 'adminpass1');
        foreach (['/admin', '/admin/accounts', '/admin/account?id=ed', '/admin/account/edit', '/admin/account/password?id=ed', '/admin/roles', '/admin/role/edit'] as $page) {
            [$path, $query] = array_pad(explode('?', $page, 2), 2, '');
            parse_str($query, $params);
            self::assertSame(200, $admin->get($path, $params)->status(), $page);
        }
    }

    public function testApiActionsNeedTheirOwnPermission(): void
    {
        $reader = $this->loggedIn('rd', 'readerpass1');
        self::assertSame(200, $reader->json('GET', '/api/recipes')->status());
        foreach ([
            ['POST', '/api/recipes', $this->recipePayload()],
            ['PUT', '/api/recipes/' . $this->recipeId, $this->recipePayload()],
            ['DELETE', '/api/recipes/' . $this->recipeId, null],
            ['POST', '/api/recipes/import', [$this->recipePayload()]],
            ['POST', '/api/recipes/import/url', ['url' => 'https://example.com/recipe']],
            ['POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/recipe']],
            ['POST', '/api/recipes/import/url/extract', ['token' => 'abc']],
        ] as [$method, $path, $body]) {
            $response = $reader->json($method, $path, $body);
            self::assertSame(403, $response->status(), "$method $path");
            self::assertSame('You do not have permission to do this.', Browser::data($response)['error']);
        }
        self::assertCount(1, $this->recipes->all());

        $editor = $this->loggedIn('ed', 'editorpass1');
        $created = $editor->json('POST', '/api/recipes', $this->recipePayload('Edna Special'));
        self::assertSame(201, $created->status());
        self::assertSame(200, $editor->json('PUT', '/api/recipes/' . Browser::data($created)['id'], $this->recipePayload('Edna Special 2'))->status());
        self::assertSame(204, $editor->json('DELETE', '/api/recipes/' . Browser::data($created)['id'])->status());
    }

    public function testExportsNeedTheirOwnPermission(): void
    {
        $reader = $this->loggedIn('rd', 'readerpass1');
        self::assertSame(403, $reader->json('GET', '/api/recipes/' . $this->recipeId . '/export')->status());
        self::assertSame(403, $reader->json('GET', '/api/recipes/export')->status());
        self::assertStringNotContainsString('filetype-json', $reader->get('/recipes/' . $this->recipeSlug)->body());
        self::assertStringNotContainsString('Export all', $reader->get('/')->body());

        $editor = $this->loggedIn('ed', 'editorpass1');
        self::assertSame(200, $editor->json('GET', '/api/recipes/' . $this->recipeId . '/export')->status());
        self::assertSame(200, $editor->json('GET', '/api/recipes/export')->status());
        self::assertStringContainsString('filetype-json', $editor->get('/recipes/' . $this->recipeSlug)->body());
        self::assertStringContainsString('Export all', $editor->get('/')->body());
    }

    public function testChangesNeedAValidCsrfToken(): void
    {
        $editor = $this->loggedIn('ed', 'editorpass1');

        $missing = $editor->json('POST', '/api/recipes', $this->recipePayload(), withToken: false);
        self::assertSame(403, $missing->status());
        self::assertStringContainsString('expired', Browser::data($missing)['error']);

        $wrong = $editor->json('POST', '/api/recipes', $this->recipePayload(), ['x-csrf-token' => str_repeat('a', 64)], withToken: false);
        self::assertSame(403, $wrong->status());

        $form = $editor->post('/account/password', ['current_password' => 'editorpass1', 'new_password' => 'a-new-password', 'confirm_password' => 'a-new-password'], withToken: false);
        self::assertSame(403, $form->status());
        self::assertTrue($this->security()->verifyPassword('ed', 'editorpass1'), 'nothing changed');

        self::assertCount(1, $this->recipes->all());
        self::assertSame(201, $editor->json('POST', '/api/recipes', $this->recipePayload())->status());
    }

    public function testATokenFromAnotherSessionIsNotAccepted(): void
    {
        $victim = $this->loggedIn('ed', 'editorpass1');
        $attacker = $this->loggedIn('rd', 'readerpass1');

        $stolen = $victim->token();
        self::assertNotSame($stolen, $attacker->token());

        $response = $attacker->json('POST', '/api/recipes', $this->recipePayload(), ['x-csrf-token' => $stolen], withToken: false);
        self::assertSame(403, $response->status());
    }

    public function testTheCsrfTokenIsRenewedAtSignIn(): void
    {
        $browser = $this->browser();
        $before = $browser->token();
        $browser->login('ed', 'editorpass1');

        self::assertNotSame($before, $browser->token());
    }

    public function testBasicAuthenticationForApiClients(): void
    {
        $basic = static fn (string $user, string $pass): array => ['authorization' => 'Basic ' . base64_encode("$user:$pass")];

        $reader = $this->browser()->request('GET', '/api/recipes', headers: $basic('rd', 'readerpass1'));
        self::assertSame(200, $reader->status());

        // no session or CSRF token involved: the credentials come with every request
        $created = $this->browser()->request('POST', '/api/recipes', headers: $basic('ed', 'editorpass1'), body: json_encode($this->recipePayload('Via Basic')));
        self::assertSame(201, $created->status());
        $forbidden = $this->browser()->request('POST', '/api/recipes', headers: $basic('rd', 'readerpass1'), body: json_encode($this->recipePayload()));
        self::assertSame(403, $forbidden->status());

        $wrong = $this->browser()->request('GET', '/api/recipes', headers: $basic('rd', 'nope'));
        self::assertSame(401, $wrong->status());
        self::assertStringContainsString('Basic realm', $wrong->headers()['WWW-Authenticate']);
        self::assertSame(401, $this->browser()->request('GET', '/api/recipes', headers: $basic('locked', '!'))->status());
        self::assertSame(401, $this->browser()->request('GET', '/', headers: $basic('rd', 'nope'))->status(), 'invalid credentials do not silently fall back to the guest');
    }

    public function testMalformedAndConflictingCredentialsFailClosed(): void
    {
        foreach (['Basic', 'Basic !!!notbase64', 'Basic ' . base64_encode('no-colon'), 'Basic  '] as $header) {
            self::assertSame(401, $this->browser()->request('GET', '/api/recipes', headers: ['authorization' => $header])->status(), $header);
        }

        // a header for another scheme is not ours to judge
        self::assertSame(200, $this->browser()->request('GET', '/api/recipes', headers: ['authorization' => 'Bearer abc'])->status());

        // signed in as ed in the browser, but the request claims to be rd: neither is trusted
        $browser = $this->loggedIn('ed', 'editorpass1');
        $conflict = $browser->request('GET', '/api/recipes', headers: ['authorization' => 'Basic ' . base64_encode('rd:readerpass1')]);
        self::assertSame(401, $conflict->status());
        $same = $browser->request('GET', '/api/recipes', headers: ['authorization' => 'Basic ' . base64_encode('ed:editorpass1')]);
        self::assertSame(200, $same->status());
    }

    public function testBasicAuthenticationIsThrottledToo(): void
    {
        $client = $this->browser('192.0.2.77');
        $basic = ['authorization' => 'Basic ' . base64_encode('ed:wrong')];
        for ($i = 0; $i < 5; $i++) {
            $client->request('GET', '/api/recipes', headers: $basic);
        }

        $good = $client->request('GET', '/api/recipes', headers: ['authorization' => 'Basic ' . base64_encode('ed:editorpass1')]);
        self::assertSame(401, $good->status(), 'even the right password is refused while locked');
    }

    private function security(): \App\Auth\SecurityController
    {
        return \App\Auth\AuthFactory::create(\App\Auth\AuthFactory::normalize([
            'password_cost' => 4,
        ]));
    }
}
