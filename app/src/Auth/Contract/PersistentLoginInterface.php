<?php

declare(strict_types=1);

namespace App\Auth\Contract;

/** Optional capability of an authenticator: revocable, expiring "remember me" tokens. */
interface PersistentLoginInterface
{
    /** @return string cookie value; it is only usable via authenticate(['token' => ...]) */
    public function issueToken(string $accountId, int $ttlSeconds): string;

    public function revokeToken(string $token): void;

    public function revokeTokens(string $accountId): void;
}
