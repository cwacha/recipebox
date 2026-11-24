<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contract\AuthenticatorInterface;
use App\Auth\Contract\PersistentLoginInterface;
use App\Auth\Exception\ValidationFailed;

/**
 * Accounts from built-in defaults plus local changes. Passwords are only stored as hashes (password API);
 * the password "!" marks an account that can never sign in (e.g. the shipped guest account).
 */
final class AuthenticatorDefault implements AuthenticatorInterface, PersistentLoginInterface
{
    /** Verified against for unknown accounts so that timing does not reveal which accounts exist. */
    private const DUMMY_HASH = '$2y$12$T.LkSvG5K6d0RYiI6JKf1eFc/bQpOGhm6aF6N5VcBhbyUh8yFAAvm';
    private const MAX_PASSWORD_BYTES = 72; // bcrypt ignores everything after 72 bytes

    public function __construct(
        private readonly LayeredStore $store,
        private readonly ?string $guestAccount = null,
        private readonly ?TokenStore $tokens = null,
        private readonly int $minPasswordLength = 8,
        private readonly ?int $hashCost = null,
    ) {
    }

    public function init(): void
    {
        $this->store->load();
    }

    public function authenticate(array $request): ?string
    {
        $accounts = [];

        if (isset($request['basic_user']) || isset($request['basic_password'])) {
            $accounts[] = $this->checkCredentials($request['basic_user'] ?? null, $request['basic_password'] ?? null);
        }
        if (isset($request['login_user']) || isset($request['login_password'])) {
            $accounts[] = $this->checkCredentials($request['login_user'] ?? null, $request['login_password'] ?? null);
        }
        if (isset($request['token'])) {
            $accounts[] = $this->checkToken($request['token']);
        }

        if ($accounts === []) {
            return ($request['guest'] ?? false) === true ? $this->guest() : null;
        }

        // anything missing, wrong or contradicting another source fails closed
        $first = $accounts[0];
        foreach ($accounts as $account) {
            if ($account === null || $account !== $first) {
                return null;
            }
        }

        return $first;
    }

    public function getAccount(string $accountId): ?array
    {
        $id = $this->normalizeId($accountId);
        $account = $id === null ? null : $this->store->get($id);

        return is_array($account) ? ['id' => $id] + $account : null;
    }

    public function getAccounts(): array
    {
        $accounts = [];
        foreach ($this->store->all() as $id => $account) {
            if (is_array($account)) {
                $accounts[(string) $id] = ['id' => (string) $id] + $account;
            }
        }
        ksort($accounts);

        return $accounts;
    }

    public function setAccount(string $accountId, ?array $account): void
    {
        $id = $this->normalizeId($accountId);
        if ($id === null) {
            throw new ValidationFailed(['id' => 'Use 1 to 64 lowercase letters, digits or . _ @ - and start with a letter.']);
        }

        if ($account === null) {
            $this->store->remove($id);

            return;
        }

        $existing = $this->store->get($id);
        $existing = is_array($existing) ? $existing : [];
        $errors = [];

        $fullname = trim((string) ($account['fullname'] ?? $existing['fullname'] ?? ''));
        if (mb_strlen($fullname) > 100) {
            $errors['fullname'] = 'The name is too long (max 100 characters).';
        }

        $email = trim((string) ($account['email'] ?? $existing['email'] ?? ''));
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'This is not a valid email address.';
        }

        $password = (string) ($existing['password'] ?? '!');
        if (isset($account['password'])) {
            $password = $this->hashPassword((string) $account['password'], $errors) ?? $password;
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        $this->store->set($id, ['password' => $password, 'fullname' => $fullname, 'email' => $email]);
    }

    public function saveAccounts(): void
    {
        $this->store->save();
    }

    public function issueToken(string $accountId, int $ttlSeconds): string
    {
        return $this->tokens?->issue($accountId, $ttlSeconds) ?? throw new \LogicException('Persistent login is not configured.');
    }

    public function revokeToken(string $token): void
    {
        $this->tokens?->revoke($token);
    }

    public function revokeTokens(string $accountId): void
    {
        $this->tokens?->revokeAll($accountId);
    }

    private function guest(): ?string
    {
        $id = $this->guestAccount === null || $this->guestAccount === '' ? null : $this->normalizeId($this->guestAccount);

        return $id !== null && $this->store->has($id) ? $id : null;
    }

    private function checkCredentials(mixed $user, mixed $password): ?string
    {
        if (!is_string($user) || !is_string($password) || $user === '' || $password === ''
            || strlen($user) > 256 || strlen($password) > 1024 || preg_match('/[\x00-\x1f]/', $user) === 1
        ) {
            return null;
        }

        $id = $this->normalizeId($user);

        return $id !== null && $this->checkPassword($id, $password) ? $id : null;
    }

    private function checkToken(mixed $token): ?string
    {
        if (!is_string($token) || $this->tokens === null) {
            return null;
        }

        $id = $this->tokens->validate($token);
        $account = $id === null ? null : $this->store->get($id);

        return is_array($account) && $this->canSignIn((string) ($account['password'] ?? '')) ? $id : null;
    }

    private function checkPassword(string $accountId, string $password): bool
    {
        $account = $this->store->get($accountId);
        $hash = is_array($account) ? ($account['password'] ?? null) : null;

        if (!is_string($hash) || !$this->canSignIn($hash)) {
            password_verify($password, self::DUMMY_HASH);

            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT, $this->hashOptions())) {
            try {
                $this->store->set($accountId, ['password' => password_hash($password, PASSWORD_DEFAULT, $this->hashOptions())] + (array) $account);
                $this->store->save();
            } catch (\Throwable $e) {
                error_log('[auth] could not upgrade a password hash: ' . $e->getMessage());
            }
        }

        return true;
    }

    private function canSignIn(string $hash): bool
    {
        return $hash !== '' && $hash !== '!';
    }

    /** @param array<string, string> $errors */
    private function hashPassword(string $password, array &$errors): ?string
    {
        if ($password === '!' || password_get_info($password)['algo'] !== null) {
            return $password; // "!" disables the account, anything that already is a hash is kept as it is
        }
        if (mb_strlen($password) < $this->minPasswordLength) {
            $errors['password'] = sprintf('The password needs at least %d characters.', $this->minPasswordLength);

            return null;
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            $errors['password'] = sprintf('The password can be at most %d bytes long.', self::MAX_PASSWORD_BYTES);

            return null;
        }

        return password_hash($password, PASSWORD_DEFAULT, $this->hashOptions());
    }

    /** @return array<string, int> */
    private function hashOptions(): array
    {
        return $this->hashCost === null ? [] : ['cost' => $this->hashCost];
    }

    private function normalizeId(string $id): ?string
    {
        $id = strtolower(trim($id));

        return preg_match('/^[a-z][a-z0-9_.@-]{0,63}$/', $id) === 1 ? $id : null;
    }
}
