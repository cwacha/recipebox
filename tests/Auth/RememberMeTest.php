<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Http\Cookie;

final class RememberMeTest extends AuthWebTestCase
{
    private function rememberCookie(Browser $browser): Cookie
    {
        foreach ($browser->last->cookies() as $cookie) {
            if ($cookie->name === 'recipebox_remember' && $cookie->value !== '') {
                return $cookie;
            }
        }
        self::fail('no remember cookie was set');
    }

    public function testNoCookieUnlessAskedFor(): void
    {
        $browser = $this->browser();
        $browser->login('ed', 'editorpass1');

        self::assertSame([], $browser->last->cookies());
        self::assertArrayNotHasKey('recipebox_remember', $browser->cookies);
    }

    public function testTheCookieHasSafeAttributesAndNoPassword(): void
    {
        $http = $this->browser();
        $http->login('ed', 'editorpass1', remember: true);
        $cookie = $this->rememberCookie($http);

        self::assertMatchesRegularExpression('/^[a-f0-9]{18}\.[a-f0-9]{64}$/', $cookie->value);
        self::assertStringNotContainsString('editorpass1', $cookie->value);
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
        self::assertFalse($cookie->secure, 'plain HTTP: a Secure cookie would never come back');
        self::assertEqualsWithDelta(time() + 30 * 86400, $cookie->expires, 5);
        self::assertSame('/', $cookie->path);

        $https = $this->browser('10.0.0.2', https: true);
        $https->login('ed', 'editorpass1', remember: true);
        self::assertTrue($this->rememberCookie($https)->secure, 'Secure over HTTPS');
    }

    public function testCookieSettingsAreConfigurable(): void
    {
        $app = $this->app(['cookie_samesite' => 'Strict', 'cookie_secure' => true, 'remember_days' => 7, 'remember_cookie' => 'rb_token']);
        $browser = $this->browser(app: $app);
        $browser->login('ed', 'editorpass1', remember: true);

        $cookie = $browser->last->cookies()[0];
        self::assertSame('rb_token', $cookie->name);
        self::assertSame('Strict', $cookie->sameSite);
        self::assertTrue($cookie->secure);
        self::assertEqualsWithDelta(time() + 7 * 86400, $cookie->expires, 5);
    }

    public function testTheCookieSignsInAnewBrowserAndIsReplacedEveryTime(): void
    {
        $first = $this->browser();
        $first->login('ed', 'editorpass1', remember: true);
        $token = $this->rememberCookie($first)->value;

        // the browser was closed: no session, only the cookie
        $second = $this->browser();
        $second->cookies['recipebox_remember'] = $token;
        $response = $second->get('/recipes/new');
        self::assertSame(200, $response->status());
        self::assertSame('ed', $second->account());
        $renewed = $this->rememberCookie($second)->value;
        self::assertNotSame($token, $renewed, 'a token is good for one use');

        // the old value is dead, the new one works
        $thief = $this->browser();
        $thief->cookies['recipebox_remember'] = $token;
        self::assertSame(302, $thief->get('/recipes/new')->status());
        self::assertArrayNotHasKey('recipebox_remember', $thief->cookies, 'the dead cookie is deleted');

        $third = $this->browser();
        $third->cookies['recipebox_remember'] = $renewed;
        self::assertSame(200, $third->get('/recipes/new')->status());
    }

    public function testTheNewCookieIsNotLostWhenTheRequestIsDenied(): void
    {
        $first = $this->browser();
        $first->login('rd', 'readerpass1', remember: true);

        $second = $this->browser();
        $second->cookies['recipebox_remember'] = $this->rememberCookie($first)->value;
        $denied = $second->get('/admin');

        self::assertSame(302, $denied->status());
        self::assertNotSame('', $this->rememberCookie($second)->value);
    }

    public function testSigningOutRevokesTheCookie(): void
    {
        $browser = $this->browser();
        $browser->login('ed', 'editorpass1', remember: true);
        $token = $this->rememberCookie($browser)->value;

        $browser->post('/logout');
        self::assertArrayNotHasKey('recipebox_remember', $browser->cookies);

        $replay = $this->browser();
        $replay->cookies['recipebox_remember'] = $token;
        self::assertSame(302, $replay->get('/recipes/new')->status(), 'the copied cookie is useless after sign-out');
    }

    public function testAPasswordChangeRevokesAllCookiesAndSessionsOfTheAccount(): void
    {
        $phone = $this->browser();
        $phone->login('ed', 'editorpass1', remember: true);
        $token = $this->rememberCookie($phone)->value;
        $laptop = $this->loggedIn('ed', 'editorpass1');

        $admin = $this->loggedIn('admin', 'adminpass1');
        $changed = $admin->post('/admin/account/password', ['id' => 'ed', 'new_password' => 'brand-new-password', 'confirm_password' => 'brand-new-password']);
        self::assertSame(303, $changed->status());

        self::assertSame(302, $laptop->get('/recipes/new')->status(), 'a running session ends');
        self::assertNull($laptop->account());
        $restart = $this->browser();
        $restart->cookies['recipebox_remember'] = $token;
        self::assertSame(302, $restart->get('/recipes/new')->status(), 'a remembered sign-in ends');
        self::assertSame(303, $this->browser()->login('ed', 'brand-new-password')->status());
    }

    public function testDisablingAnAccountEndsItsSessions(): void
    {
        $editor = $this->loggedIn('ed', 'editorpass1');
        $admin = $this->loggedIn('admin', 'adminpass1');

        $admin->post('/admin/account/password', ['id' => 'ed', 'disable' => '1']);

        self::assertSame(302, $editor->get('/recipes/new')->status());
        self::assertSame(401, $this->browser()->login('ed', 'editorpass1')->status());
    }
}
