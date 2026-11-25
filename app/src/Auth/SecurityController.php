<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contract\AuthenticatorInterface;
use App\Auth\Contract\AuthorizerInterface;
use App\Auth\Contract\PersistentLoginInterface;
use App\Auth\Exception\LockoutPrevented;

/**
 * The application-facing facade: authenticates requests, answers permission checks and combines account data
 * with role and permission data. It only talks to the two provider contracts, so providers can be swapped.
 */
final class SecurityController
{
    /** What an account needs to be able to run the administration. */
    private const ADMIN_PERMISSIONS = ['/admin/account/edit', '/admin/role/edit', 'account_edit', 'role_edit'];
    private const DEFAULT_ADMIN = 'admin';
    private const DEFAULT_PASSWORD = 'admin';

    public function __construct(
        private AuthenticatorInterface $authenticator,
        private AuthorizerInterface $authorizer,
        private bool $authenticationEnabled,
    ) {
    }

    public function init(): void
    {
        $this->authenticator->init();
        $this->authorizer->init();
    }

    public function isEnabled(): bool
    {
        return $this->authenticationEnabled;
    }

    /** @param array<string, mixed> $request see AuthenticatorInterface::authenticate() */
    public function authenticate(array $request): ?string
    {
        return $this->authenticator->authenticate($request);
    }

    public function authorize(?string $accountId, ?string $permission): bool
    {
        return $this->authorizer->authorize($accountId, $permission);
    }

    public function hasPermission(?string $accountId, ?string $permission): bool
    {
        return !$this->authenticationEnabled
            || $this->authorize($accountId, $permission);
    }

    /** @return array<string, mixed>|null account data including 'roles' and 'permissions' */
    public function getAccount(string $accountId): ?array
    {
        $account = $this->authenticator->getAccount($accountId);
        if ($account === null) {
            return null;
        }

        $account['roles'] = $this->authorizer->getRoles($accountId);
        $account['permissions'] = $this->authorizer->getPermissions($accountId);

        return $account;
    }

    /** Like getAccount() but without the password hash, safe to hand to templates. */
    public function getPublicAccount(string $accountId): ?array
    {
        $account = $this->getAccount($accountId);
        if ($account === null) {
            return null;
        }
        $account['can_sign_in'] = $this->canSignIn($accountId);
        unset($account['password']);

        return $account;
    }

    /** @return array<string, array<string, mixed>> */
    public function getAccounts(): array
    {
        $accounts = [];
        foreach (array_keys($this->authenticator->getAccounts()) as $id) {
            $accounts[(string) $id] = $this->getPublicAccount((string) $id);
        }

        return $accounts;
    }

    /** Whether the account exists and has a usable password (the password "!" means it never can). */
    public function canSignIn(string $accountId): bool
    {
        $hash = $this->authenticator->getAccount($accountId)['password'] ?? '';

        return is_string($hash) && $hash !== '' && $hash !== '!';
    }

    /** A fingerprint that changes when the password changes; sessions use it to end after a password change. */
    public function passwordVersion(string $accountId): ?string
    {
        $hash = $this->authenticator->getAccount($accountId)['password'] ?? null;

        return is_string($hash) ? substr(hash('sha256', $hash), 0, 16) : null;
    }

    public function verifyPassword(string $accountId, string $password): bool
    {
        return $this->authenticate(['login_user' => $accountId, 'login_password' => $password]) === strtolower($accountId);
    }

    /** True while the shipped administrator still has the shipped password. */
    public function usesDefaultPassword(string $accountId): bool
    {
        $hash = $this->authenticator->getAccount($accountId)['password'] ?? null;

        return $accountId === self::DEFAULT_ADMIN && is_string($hash) && $this->canSignIn($accountId)
            && password_verify(self::DEFAULT_PASSWORD, $hash);
    }

    /**
     * Creates or updates an account and replaces its roles.
     *
     * @param array<string, mixed> $data fullname, email and optionally password
     * @param list<string>         $roles
     */
    public function saveAccount(string $accountId, array $data, array $roles): void
    {
        $this->guarded(function () use ($accountId, $data, $roles): void {
            $this->authenticator->setAccount($accountId, $data);
            $this->authorizer->setRoles($accountId, $roles);
        }, accounts: true, access: true);

        if (isset($data['password'])) {
            $this->revokeTokens($accountId);
        }
    }

    public function changePassword(string $accountId, string $password): void
    {
        $this->guarded(fn () => $this->authenticator->setAccount($accountId, ['password' => $password]), accounts: true);
        $this->revokeTokens($accountId);
    }

    public function deleteAccount(string $accountId): void
    {
        $this->guarded(function () use ($accountId): void {
            $this->authenticator->setAccount($accountId, null);
            $this->authorizer->setRoles($accountId, null);
        }, accounts: true, access: true);
        $this->revokeTokens($accountId);
    }

    /** @return array<string, list<string>> role ID => permissions */
    public function getRoleDefinitions(): array
    {
        return $this->authorizer->getRoles();
    }

    /** @return list<string> every permission that any role mentions */
    public function knownPermissions(): array
    {
        return $this->authorizer->getRoles()[AuthorizerDefault::ALL_ROLE] ?? [];
    }

    /** @param list<string> $permissions */
    public function saveRole(string $roleId, array $permissions): void
    {
        $this->guarded(fn () => $this->authorizer->setRolePermissions($roleId, $permissions), roles: true);
    }

    public function deleteRole(string $roleId): void
    {
        $this->guarded(fn () => $this->authorizer->setRolePermissions($roleId, null), roles: true, access: true);
    }

    /** @return list<string> IDs of the accounts that have the role */
    public function accountsWithRole(string $roleId): array
    {
        $ids = [];
        foreach (array_keys($this->authenticator->getAccounts()) as $id) {
            if (in_array($roleId, $this->authorizer->getRoles((string) $id), true)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    public function issueToken(string $accountId, int $ttlSeconds): ?string
    {
        return $this->authenticator instanceof PersistentLoginInterface ? $this->authenticator->issueToken($accountId, $ttlSeconds) : null;
    }

    public function revokeToken(string $token): void
    {
        if ($this->authenticator instanceof PersistentLoginInterface) {
            $this->authenticator->revokeToken($token);
        }
    }

    public function revokeTokens(string $accountId): void
    {
        if ($this->authenticator instanceof PersistentLoginInterface) {
            $this->authenticator->revokeTokens($accountId);
        }
    }

    /**
     * Applies a change in memory, refuses it if nobody could administer the application afterwards (and
     * forgets it), otherwise persists it.
     */
    private function guarded(callable $change, bool $accounts = false, bool $roles = false, bool $access = false): void
    {
        try {
            $change();
            if ($this->authenticationEnabled && !$this->someoneCanAdminister()) {
                throw new LockoutPrevented();
            }
        } catch (\Throwable $e) {
            $this->init(); // drop the unsaved change

            throw $e;
        }

        if ($roles) {
            $this->authorizer->saveRoles();
        }
        if ($accounts) {
            $this->authenticator->saveAccounts();
        }
        if ($access) {
            $this->authorizer->saveAccess();
        }
    }

    private function someoneCanAdminister(): bool
    {
        foreach (array_keys($this->authenticator->getAccounts()) as $id) {
            $id = (string) $id;
            if (!$this->canSignIn($id)) {
                continue;
            }
            $permissions = $this->authorizer->getPermissions($id);
            if (in_array(AuthorizerDefault::WILDCARD, $permissions, true) || array_diff(self::ADMIN_PERMISSIONS, $permissions) === []) {
                return true;
            }
        }

        return false;
    }
}
