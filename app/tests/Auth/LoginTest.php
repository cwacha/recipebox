<?php

declare(strict_types=1);

namespace Tests\Auth;

final class LoginTest extends AuthWebTestCase
{
    public function testGuestsCanReadButNothingElse(): void
    {
        $guest = $this->browser();

        $home = $guest->get('/');
        self::assertSame(200, $home->status());
        self::assertStringContainsString('Sign in', $home->body());
        self::assertStringNotContainsString('New recipe', $home->body());
        self::assertStringNotContainsString('href="/admin"', $home->body());
        self::assertSame(200, $guest->get('/recipes/' . $this->recipeSlug)->status());
        self::assertStringNotContainsString('btnDelete', $guest->get('/recipes/' . $this->recipeSlug)->body());
        self::assertSame(200, $guest->get('/api/recipes')->status());

        $admin = $guest->get('/admin');
        self::assertSame(302, $admin->status());
        self::assertSame('/login?next=%2Fadmin', Browser::location($admin));
        self::assertSame(302, $guest->get('/recipes/new')->status());

        $write = $guest->json('POST', '/api/recipes', $this->recipePayload(), withToken: false);
        self::assertSame(401, $write->status());
        self::assertSame('Please sign in.', Browser::data($write)['error']);
        self::assertSame(401, $guest->json('DELETE', '/api/recipes/' . $this->recipeId, withToken: false)->status());
        self::assertCount(1, $this->recipes->all());
    }

    public function testApiAnswersWithoutTriggeringTheBrowsersPasswordDialogForScripts(): void
    {
        $guest = $this->browser();

        $script = $guest->json('POST', '/api/recipes', [], withToken: false);
        self::assertArrayNotHasKey('WWW-Authenticate', $script->headers());

        $client = $guest->request('POST', '/api/recipes');
        self::assertSame(401, $client->status());
        self::assertStringContainsString('Basic', $client->headers()['WWW-Authenticate']);
    }

    public function testWithoutGuestAccountEverybodyHasToSignIn(): void
    {
        $app = $this->app(['guest_account' => '']);
        $visitor = $this->browser(app: $app);

        self::assertSame('/login?next=%2F', Browser::location($visitor->get('/')));
        self::assertSame(401, $visitor->get('/api/recipes')->status());
        self::assertSame(200, $visitor->get('/login')->status());
        self::assertStringNotContainsString('Continue as guest', $visitor->get('/login')->body());

        self::assertSame(303, $visitor->login('rd', 'readerpass1')->status());
        self::assertSame(200, $visitor->get('/')->status());
    }

    public function testSigningInAndOut(): void
    {
        $browser = $this->browser();

        $form = $browser->get('/login');
        self::assertSame(200, $form->status());
        self::assertMatchesRegularExpression('/name="_csrf" value="[a-f0-9]{64}"/', $form->body());
        self::assertStringContainsString('Continue as guest', $form->body());

        $wrong = $browser->login('ed', 'wrong-password');
        self::assertSame(401, $wrong->status());
        self::assertStringContainsString('Invalid username or password.', $wrong->body());
        self::assertNull($browser->account());

        $unknown = $browser->login('nobody', 'wrong-password');
        self::assertStringContainsString('Invalid username or password.', $unknown->body(), 'same message, nothing reveals which accounts exist');

        $regenerated = $browser->session->regenerations();
        $ok = $browser->login('ED', 'editorpass1');
        self::assertSame(303, $ok->status());
        self::assertSame('/', Browser::location($ok));
        self::assertSame('ed', $browser->account());
        self::assertGreaterThan($regenerated, $browser->session->regenerations(), 'the session ID is renewed on sign-in');
        self::assertStringContainsString('Welcome back, Edna Editor', $this->flashMessages($browser));

        $home = $browser->get('/');
        self::assertStringContainsString('New recipe', $home->body());
        self::assertStringContainsString('Signed in as <strong>ed</strong>', $home->body());
        self::assertSame(200, $browser->get('/recipes/new')->status());

        $noToken = $browser->post('/logout', withToken: false);
        self::assertSame(403, $noToken->status());
        self::assertSame('ed', $browser->account(), 'a logout without CSRF token does nothing');

        $out = $browser->post('/logout');
        self::assertSame(303, $out->status());
        self::assertNull($browser->account());
        self::assertSame('You have been signed out.', $this->flashMessages($browser));
        self::assertSame(302, $browser->get('/recipes/new')->status());
    }

    public function testAccountsWithoutPasswordCannotSignIn(): void
    {
        $browser = $this->browser();

        foreach (['locked' => '!', 'guest' => '!', 'guest ' => 'guest'] as $user => $password) {
            self::assertSame(401, $browser->login($user, $password)->status());
        }
        self::assertNull($browser->account());
    }

    public function testOnlyPathsInsideTheApplicationAreAcceptedAsReturnAddress(): void
    {
        foreach ([
            '/recipes/new' => '/recipes/new',
            '/admin/accounts?x=1' => '/admin/accounts?x=1',
            '//evil.example/x' => '/',
            'https://evil.example/' => '/',
            '/\\evil.example' => '/',
            'recipes/new' => '/',
            '/login' => '/',
            '' => '/',
        ] as $next => $expected) {
            $browser = $this->browser();
            $response = $browser->login('ed', 'editorpass1', next: (string) $next);
            self::assertSame($expected, Browser::location($response), "next=$next");
        }

        // and the page that asked for the sign-in is where you end up
        $browser = $this->browser();
        self::assertSame('/login?next=%2Frecipes%2Fnew', Browser::location($browser->get('/recipes/new')));
        self::assertStringContainsString('name="next" value="/recipes/new"', $browser->get('/login', ['next' => '/recipes/new'])->body());
    }

    public function testTooManyFailedAttemptsLockTheAccountForThatAddress(): void
    {
        $attacker = $this->browser('203.0.113.9');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $attacker->login('ed', "guess-$i")->status());
        }

        $locked = $attacker->login('ed', 'editorpass1');
        self::assertSame(429, $locked->status());
        self::assertStringContainsString('Too many failed attempts', $locked->body());
        self::assertNull($attacker->account());

        self::assertSame(303, $this->browser('198.51.100.7')->login('ed', 'editorpass1')->status(), 'other addresses are not affected');
        self::assertSame(401, $attacker->login('rd', 'wrong')->status(), 'another account from the same address is still tried normally');
    }

    public function testASuccessfulSignInResetsTheFailureCount(): void
    {
        $browser = $this->browser('203.0.113.50');
        for ($i = 0; $i < 4; $i++) {
            $browser->login('ed', 'wrong');
        }
        self::assertSame(303, $browser->login('ed', 'editorpass1')->status());

        $other = $this->browser('203.0.113.50');
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $other->login('ed', 'wrong')->status());
        }
    }

    public function testDefaultAdministratorPasswordIsFlaggedAndForcesAChangePrompt(): void
    {
        $app = new \App\Application(dirname(__DIR__, 2), $this->recipes, config: ['auth' => [
            'defaults_dir' => dirname(__DIR__, 2) . '/defaults',
            'state_dir' => $this->dir . '/state-default',
            'password_cost' => 4,
        ]]);
        $browser = $this->browser(app: $app);

        $response = $browser->login('admin', 'admin');
        self::assertSame(303, $response->status());
        self::assertSame('/account/password', Browser::location($response), 'sent straight to the password page');
        self::assertStringContainsString('default password', $this->flashMessages($browser));
        self::assertStringContainsString('still uses the default password', $browser->get('/admin')->body());
    }

    public function testWithAuthenticationSwitchedOffEverythingIsOpen(): void
    {
        $open = $this->browser(app: $this->app(['enabled' => false]));

        self::assertSame(200, $open->get('/admin')->status());
        self::assertSame(200, $open->get('/recipes/new')->status());
        self::assertSame(302, $open->get('/login')->status());
        self::assertStringNotContainsString('Sign in', $open->get('/')->body());

        $created = $open->request('POST', '/api/recipes', body: json_encode($this->recipePayload()));
        self::assertSame(201, $created->status(), 'no login, no CSRF token needed');
    }
}
