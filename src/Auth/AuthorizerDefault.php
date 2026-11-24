<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contract\AuthorizerInterface;
use App\Auth\Exception\ValidationFailed;

/**
 * Roles (role ID => permissions) and the access table (account ID => role IDs), each as built-in defaults plus
 * local changes. In the files both lists are comma separated strings.
 *
 * The role "all" is maintained automatically: it contains its built-in permissions plus every permission any other
 * role mentions, so it always enumerates everything that exists. It cannot be edited or deleted.
 */
final class AuthorizerDefault implements AuthorizerInterface
{
    public const ALL_ROLE = 'all';
    public const WILDCARD = 'ALL';
    /** Granted to every identified account, no role needed. */
    private const IMPLICIT = ['show', 'login', '/login', 'logout', '/logout'];

    /** @var array<string, list<string>> */
    private array $roles = [];
    /** @var array<string, list<string>> */
    private array $access = [];
    /** @var list<string> the built-in permissions of the role "all", before everything else is added to it */
    private array $allBase = [];

    public function __construct(
        private readonly LayeredStore $rolesStore,
        private readonly LayeredStore $accessStore,
    ) {
    }

    public function init(): void
    {
        $this->rolesStore->load();
        $this->accessStore->load();

        $this->roles = [];
        foreach ($this->rolesStore->all() as $id => $permissions) {
            $this->roles[(string) $id] = $this->toList($permissions);
        }
        $this->access = [];
        foreach ($this->accessStore->all() as $id => $roles) {
            $this->access[(string) $id] = $this->toList($roles);
        }

        $this->allBase = $this->roles[self::ALL_ROLE] ?? [];
        $this->deriveAllRole();
    }

    public function authorize(?string $accountId, ?string $permission): bool
    {
        if ($accountId === null) {
            return false;
        }

        if ($permission === null || $permission === '' || in_array($permission, self::IMPLICIT, true)) {
            return true;
        }

        $permissions = $this->getPermissions($accountId);

        return in_array(self::WILDCARD, $permissions, true) || in_array($permission, $permissions, true);
    }

    public function getRoles(?string $accountId = null): array
    {
        return $accountId === null ? $this->roles : ($this->access[strtolower($accountId)] ?? []);
    }

    public function getPermissions(string $accountId): array
    {
        $permissions = [];
        foreach ($this->getRoles($accountId) as $roleId) {
            foreach ($this->roles[$roleId] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public function setRoles(string $accountId, ?array $roles): void
    {
        $id = strtolower(trim($accountId));
        $roles = $this->toList($roles ?? []);

        $unknown = array_values(array_filter($roles, fn (string $role): bool => !isset($this->roles[$role])));
        if ($unknown !== []) {
            throw new ValidationFailed(['roles' => 'Unknown role: ' . implode(', ', $unknown) . '.']);
        }

        if ($roles === []) {
            unset($this->access[$id]);
            $this->accessStore->remove($id);

            return;
        }

        $this->access[$id] = $roles;
        $this->accessStore->set($id, implode(',', $roles));
    }

    public function setRolePermissions(string $roleId, ?array $permissions): void
    {
        $id = strtolower(trim($roleId));
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) !== 1) {
            throw new ValidationFailed(['id' => 'Use 1 to 32 lowercase letters, digits, - or _ and start with a letter.']);
        }
        if ($id === self::ALL_ROLE) {
            throw new ValidationFailed(['id' => 'The role "all" is maintained automatically and cannot be changed.']);
        }

        $permissions = $this->toList($permissions ?? []);

        if ($permissions === []) {
            $this->removeRole($id);
            $this->deriveAllRole();

            return;
        }

        $invalid = array_values(array_filter($permissions, fn (string $p): bool => !self::isValidPermission($p)));
        if ($invalid !== []) {
            throw new ValidationFailed(['permissions' => 'Not a valid permission: ' . implode(', ', $invalid)
                . '. Views start with a slash (/admin), actions are plain words (recipe_edit).']);
        }

        $this->roles[$id] = $permissions;
        $this->rolesStore->set($id, implode(',', $permissions));
        $this->deriveAllRole();
    }

    public function saveAccess(): void
    {
        $this->accessStore->save();
    }

    public function saveRoles(): void
    {
        $this->rolesStore->save();
    }

    public static function isValidPermission(string $permission): bool
    {
        return strlen($permission) <= 80
            && (preg_match('#^/[A-Za-z0-9_\-/]*$#', $permission) === 1 || preg_match('/^[A-Za-z][A-Za-z0-9_\-.:]*$/', $permission) === 1);
    }

    /** Deleting a role also takes it away from every account. */
    private function removeRole(string $id): void
    {
        unset($this->roles[$id]);
        $this->rolesStore->remove($id);

        foreach ($this->access as $accountId => $roles) {
            if (!in_array($id, $roles, true)) {
                continue;
            }
            $remaining = array_values(array_diff($roles, [$id]));
            if ($remaining === []) {
                unset($this->access[$accountId]);
                $this->accessStore->remove($accountId);
            } else {
                $this->access[$accountId] = $remaining;
                $this->accessStore->set($accountId, implode(',', $remaining));
            }
        }
    }

    private function deriveAllRole(): void
    {
        $all = $this->allBase;
        foreach ($this->roles as $id => $permissions) {
            if ($id !== self::ALL_ROLE) {
                $all = [...$all, ...array_diff($permissions, [self::WILDCARD])];
            }
        }

        $this->roles = [self::ALL_ROLE => array_values(array_unique($all))] + array_diff_key($this->roles, [self::ALL_ROLE => true]);
    }

    /**
     * @return list<string>
     */
    private function toList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_map(static fn (mixed $item): string => trim((string) $item), $items);

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }
}
