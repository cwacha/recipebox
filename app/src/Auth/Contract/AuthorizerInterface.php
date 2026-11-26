<?php

declare(strict_types=1);

namespace App\Auth\Contract;

interface AuthorizerInterface
{
    public function init(): void;

    public function authorize(?string $accountId, ?string $permission): bool;

    /**
     * With no account ID, return role definitions (role ID => permissions); otherwise return
     * the role IDs assigned to that account.
     *
     * @return array<string, mixed>
     */
    public function getRoles(?string $accountId = null): array;

    /** @return list<string> */
    public function getPermissions(string $accountId): array;

    /**
     * A null or empty role list removes the account's role assignment.
     *
     * @param  list<string>|null $roles
     * @throws \App\Auth\Exception\ValidationFailed
     */
    public function setRoles(string $accountId, ?array $roles): void;

    /**
     * A null or empty permission list removes the role definition.
     *
     * @param  list<string>|null $permissions
     * @throws \App\Auth\Exception\ValidationFailed
     */
    public function setRolePermissions(string $roleId, ?array $permissions): void;

    /** @throws \App\Exception\StorageFailed */
    public function saveAccess(): void;

    /** @throws \App\Exception\StorageFailed */
    public function saveRoles(): void;
}
