<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Identity;
use App\Auth\SecurityController;
use App\Auth\WebAuth;
use App\Http\Request;
use App\Http\Response;
use App\View\View;

/** Sign in, sign out and changing the own password. */
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly SecurityController $security,
        private readonly WebAuth $webAuth,
        private readonly string $templateDir,
        private readonly bool $guestsAllowed,
    ) {
    }

    public function loginForm(Request $request): Response
    {
        $identity = $this->identity($request);
        if (!$this->security->isEnabled() || $identity->isUser()) {
            return $this->redirect($request->basePath . $this->safeNext($request->query('next')), 302);
        }

        return $this->loginPage($request, $this->safeNext($request->query('next')), '', null);
    }

    public function login(Request $request): Response
    {
        $username = trim((string) ($request->post['username'] ?? ''));
        $password = (string) ($request->post['password'] ?? '');
        $next = $this->safeNext((string) ($request->post['next'] ?? ''));

        [$account, $retryAfter] = $this->webAuth->attempt($request, $username, $password);

        if ($account === null) {
            $error = $retryAfter > 0
                ? sprintf('Too many failed attempts. Please wait %d minute(s) before trying again.', (int) ceil($retryAfter / 60))
                : 'Invalid username or password.';

            return $this->loginPage($request, $next, $username, $error, $retryAfter > 0 ? 429 : 401);
        }

        $session = $request->session();
        $this->webAuth->startSession($session, $account);
        $session->flash('success', 'Welcome back, ' . ($this->security->getPublicAccount($account)['fullname'] ?: $account) . '.');
        if ($this->security->usesDefaultPassword($account)) {
            $session->flash('warning', 'This account still has the default password. Please change it now.');
            $next = '/account/password';
        }

        $response = $this->redirect($request->basePath . $next, 303);
        if (!empty($request->post['remember']) && ($cookie = $this->webAuth->rememberCookie($request, $account)) !== null) {
            $response->addCookie($cookie);
        }

        return $response;
    }

    public function logout(Request $request): Response
    {
        $response = $this->redirect($request->basePath . '/', 303);
        foreach ($this->webAuth->signOut($request, $request->session()) as $cookie) {
            $response->addCookie($cookie);
        }
        $request->session()->flash('success', 'You have been signed out.');

        return $response;
    }

    public function passwordForm(Request $request): Response
    {
        return $this->passwordPage($request, []);
    }

    public function passwordSave(Request $request): Response
    {
        $account = $this->identity($request)->accountId;
        if ($account === null || !$this->identity($request)->isUser()) {
            return $this->redirect($request->basePath . '/login', 302);
        }

        $errors = [];
        if (!$this->security->verifyPassword($account, (string) ($request->post['current_password'] ?? ''))) {
            $errors['current_password'] = 'The current password is not correct.';
        }
        $new = (string) ($request->post['new_password'] ?? '');
        if ($new !== (string) ($request->post['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'The two new passwords are not the same.';
        }

        if ($errors === []) {
            try {
                $this->security->changePassword($account, $new);
            } catch (\App\Auth\Exception\ValidationFailed $e) {
                $errors = ['new_password' => $e->errors['password'] ?? $e->getMessage()];
            }
        }
        if ($errors !== []) {
            return $this->passwordPage($request, $errors, 422);
        }

        $this->webAuth->refreshSession($request->session(), $account);
        $request->session()->flash('success', 'Your password has been changed.');

        return $this->redirect($request->basePath . '/', 303);
    }

    /** @param array<string, string> $errors */
    private function passwordPage(Request $request, array $errors, int $status = 200): Response
    {
        $identity = $this->identity($request);

        return $this->html($this->view($request)->page('account_password', [
            'accountId' => $identity->accountId,
            'errors' => $errors,
        ], 'Change password – ' . $this->appTitle($request)), $status);
    }

    private function loginPage(Request $request, string $next, string $username, ?string $error, int $status = 200): Response
    {
        return $this->html($this->view($request)->page('login', [
            'next' => $next,
            'username' => $username,
            'error' => $error,
            'guestsAllowed' => $this->guestsAllowed,
        ], 'Sign in – ' . $this->appTitle($request), 'login'), $status);
    }

    /** Only paths inside this application are accepted as the place to go after signing in. */
    private function safeNext(string $next): string
    {
        $valid = preg_match('#^/(?![/\\\\])[^\s]*$#', $next) === 1 && !str_starts_with($next, '/login') && !str_starts_with($next, '/logout');

        return $valid ? $next : '/';
    }

    private function identity(Request $request): Identity
    {
        return $request->attribute('identity');
    }

    private function view(Request $request): View
    {
        return View::forRequest($this->templateDir, $request);
    }
}
