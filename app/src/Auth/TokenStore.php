<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Persistent-login ("remember me") tokens. The cookie holds "selector.validator"; only a SHA-256 hash of the
 * validator is stored, so neither the file nor the cookie ever contains a password. Tokens expire and can be revoked.
 */
final class TokenStore
{
    public function __construct(private readonly JsonFile $file)
    {
    }

    public function issue(string $accountId, int $ttlSeconds): string
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $now = time();

        $this->file->modify(function (array $tokens) use ($selector, $validator, $accountId, $ttlSeconds, $now): array {
            $tokens = $this->withoutExpired($tokens, $now);
            $tokens[$selector] = [
                'hash' => hash('sha256', $validator),
                'account' => $accountId,
                'created' => $now,
                'expires' => $now + $ttlSeconds,
            ];

            return $tokens;
        });

        return $selector . '.' . $validator;
    }

    /** @return string|null the account ID of a valid token */
    public function validate(string $cookie): ?string
    {
        if (preg_match('/^([a-f0-9]{18})\.([a-f0-9]{64})$/', $cookie, $m) !== 1) {
            return null;
        }

        $entry = $this->file->read()[$m[1]] ?? null;
        if (!is_array($entry) || (int) ($entry['expires'] ?? 0) <= time()) {
            return null;
        }
        if (!hash_equals((string) ($entry['hash'] ?? ''), hash('sha256', $m[2]))) {
            // right selector, wrong secret: somebody is guessing, so the token is burned
            $this->revoke($cookie);

            return null;
        }

        return (string) $entry['account'];
    }

    public function revoke(string $cookie): void
    {
        $selector = explode('.', $cookie, 2)[0];
        if (preg_match('/^[a-f0-9]{18}$/', $selector) === 1) {
            $this->file->modify(static function (array $tokens) use ($selector): array {
                unset($tokens[$selector]);

                return $tokens;
            });
        }
    }

    public function revokeAll(string $accountId): void
    {
        $this->file->modify(static fn (array $tokens): array => array_filter(
            $tokens,
            static fn (mixed $t): bool => !is_array($t) || ($t['account'] ?? null) !== $accountId,
        ));
    }

    /**
     * @param  array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function withoutExpired(array $tokens, int $now): array
    {
        return array_filter($tokens, static fn (mixed $t): bool => is_array($t) && (int) ($t['expires'] ?? 0) > $now);
    }
}
