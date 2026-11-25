<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Cookie;
use App\Http\Request;
use App\Http\Session;

/**
 * Connects the security controller to HTTP: finds out who makes a request (session, HTTP Basic, persistent-login
 * cookie, guest), signs users in and out and handles the login throttle and the cookies.
 */
final class WebAuth
{
    /** @param array<string, mixed> $config normalized "auth" configuration */
    public function __construct(
        private readonly SecurityController $security,
        private readonly LoginThrottle $throttle,
        private readonly array $config,
    ) {
    }

    public function identify(Request $request, Session $session): Identity
    {
        if (!$this->security->isEnabled()) {
            return new Identity(null, Identity::DISABLED);
        }

        $sessionAccount = $this->sessionAccount($session);

        $basic = $request->basicAuth();
        if ($basic === false) {
            return new Identity(null, Identity::INVALID); // malformed credentials fail closed
        }
        if ($basic !== null) {
            $account = $this->checkPassword($request, $basic[0], $basic[1], Identity::BASIC);
            if ($account === null || ($sessionAccount !== null && $sessionAccount !== $account)) {
                return new Identity(null, Identity::INVALID);
            }

            return new Identity($account, Identity::BASIC);
        }

        if ($sessionAccount !== null) {
            return new Identity($sessionAccount, Identity::SESSION);
        }

        $cookies = [];
        $token = $request->cookies[$this->config['remember_cookie']] ?? null;
        if (is_string($token) && $token !== '') {
            $account = $this->security->authenticate(['token' => $token]);
            if ($account !== null) {
                // a token is only good once: swap it for a fresh one
                $this->security->revokeToken($token);
                $this->startSession($session, $account);
                $cookies[] = $this->rememberCookie($request, $account) ?? $this->cookie($request, 'remember_cookie', '')->expired();

                return new Identity($account, Identity::TOKEN, $cookies);
            }
            $cookies[] = $this->cookie($request, 'remember_cookie', '')->expired();
        }

        $guest = $this->security->authenticate(['guest' => true]);

        return new Identity($guest, $guest === null ? Identity::NONE : Identity::GUEST, $cookies);
    }

    /**
     * Checks a username and password typed into the login form.
     *
     * @return array{?string, int} the account ID on success; otherwise null and, if the attempt was refused
     *                             because of too many failures, the seconds to wait
     */
    public function attempt(Request $request, string $user, string $password): array
    {
        $retry = $this->retryAfter($request, $user);
        if ($retry > 0) {
            return [null, $retry];
        }

        return [$this->checkPassword($request, $user, $password, 'login'), 0];
    }

    /** Signs the account in for this session. The session ID changes, so an old one cannot be reused. */
    public function startSession(Session $session, string $accountId): void
    {
        $session->regenerate();
        $session->set('account', $accountId);
        $session->set('pwv', $this->security->passwordVersion($accountId));
        (new Csrf())->rotate($session);
    }

    /** After a password change: keep this session signed in although the password fingerprint changed. */
    public function refreshSession(Session $session, string $accountId): void
    {
        $session->set('pwv', $this->security->passwordVersion($accountId));
        $session->regenerate();
    }

    public function rememberCookie(Request $request, string $accountId): ?Cookie
    {
        $days = (int) $this->config['remember_days'];
        $token = $this->security->issueToken($accountId, $days * 86400);

        return $token === null ? null : $this->cookie($request, 'remember_cookie', $token, time() + $days * 86400);
    }

    /** @return list<Cookie> cookies that remove the persistent login */
    public function signOut(Request $request, Session $session): array
    {
        $token = $request->cookies[$this->config['remember_cookie']] ?? null;
        if (is_string($token) && $token !== '') {
            $this->security->revokeToken($token);
        }
        $session->clear();

        return [$this->cookie($request, 'remember_cookie', '')->expired()];
    }

    /** @return array<string, mixed> options for the native session */
    public function sessionOptions(Request $request): array
    {
        return [
            'name' => $this->config['session_name'],
            'save_path' => $this->config['sessions_dir'],
            'path' => $request->basePath === '' ? '/' : $request->basePath,
            'secure' => $this->secure($request),
            'samesite' => $this->config['cookie_samesite'],
        ];
    }

    private function sessionAccount(Session $session): ?string
    {
        $account = $session->get('account');
        if (!is_string($account)) {
            return null;
        }

        // the account may have been deleted or locked, or its password changed since: that ends the session
        if ($this->security->canSignIn($account) && $this->security->passwordVersion($account) === $session->get('pwv')) {
            return $account;
        }
        $session->clear();

        return null;
    }

    private function checkPassword(Request $request, string $user, string $password, string $source): ?string
    {
        $retry = $this->retryAfter($request, $user);
        $field = $source === Identity::BASIC ? 'basic' : 'login';
        $account = $retry > 0 ? null : $this->security->authenticate([$field . '_user' => $user, $field . '_password' => $password]);

        if ($account !== null) {
            $this->throttle->clear($this->userKey($user, $request));

            return $account;
        }
        if ($retry === 0) {
            $limit = (int) $this->config['max_login_attempts'];
            $this->throttle->fail($this->userKey($user, $request), $limit);
            $this->throttle->fail($this->ipKey($request), $limit * 4);
            error_log(sprintf('[auth] failed sign-in (%s) for "%s" from %s', $source, $this->printable($user), $request->ip()));
        }

        return null;
    }

    private function retryAfter(Request $request, string $user): int
    {
        return max($this->throttle->retryAfter($this->userKey($user, $request)), $this->throttle->retryAfter($this->ipKey($request)));
    }

    private function userKey(string $user, Request $request): string
    {
        return 'user:' . strtolower(trim($user)) . '|' . $request->ip();
    }

    private function ipKey(Request $request): string
    {
        return 'ip:' . $request->ip();
    }

    private function printable(string $value): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 64);
    }

    private function secure(Request $request): bool
    {
        $setting = $this->config['cookie_secure'];

        return $setting === 'auto' ? $request->isSecure() : (bool) $setting;
    }

    private function cookie(Request $request, string $setting, string $value, int $expires = 0): Cookie
    {
        return new Cookie(
            (string) $this->config[$setting],
            $value,
            $expires,
            $request->basePath === '' ? '/' : $request->basePath,
            $this->secure($request),
            true,
            (string) $this->config['cookie_samesite'],
        );
    }
}
