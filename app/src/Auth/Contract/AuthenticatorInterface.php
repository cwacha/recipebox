<?php

declare(strict_types=1);

namespace App\Auth\Contract;

interface AuthenticatorInterface
{
    public function init(): void;

    /**
     * Return the account ID on success, otherwise null. Supported keys of $request:
     * basic_user/basic_password (HTTP Basic), login_user/login_password (login form),
     * token (persistent-login cookie value) and guest (true to ask for the guest account).
     * Several credential sources are accepted only if they all identify the same account.
     *
     * @param array<string, mixed> $request
     */
    public function authenticate(array $request): ?string;

    /** @return array<string, mixed>|null */
    public function getAccount(string $accountId): ?array;

    /** @return array<string, array<string, mixed>> */
    public function getAccounts(): array;

    /**
     * Creates or updates an account (fields not given are kept); a null account removes it.
     *
     * @param  array<string, mixed>|null $account
     * @throws \App\Auth\Exception\ValidationFailed
     */
    public function setAccount(string $accountId, ?array $account): void;

    /** @throws \App\Exception\StorageFailed */
    public function saveAccounts(): void;
}
