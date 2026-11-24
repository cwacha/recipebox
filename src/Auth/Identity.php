<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Cookie;

/** Who is making the current request and how that was established. */
final readonly class Identity
{
    public const SESSION = 'session';
    public const BASIC = 'basic';
    public const TOKEN = 'token';
    public const GUEST = 'guest';
    public const NONE = 'none';
    public const DISABLED = 'disabled';
    public const INVALID = 'invalid';

    /** @param list<Cookie> $cookies cookies to set on the response (new remember token, cleanup) */
    public function __construct(
        public ?string $accountId,
        public string $via,
        public array $cookies = [],
    ) {
    }

    public function isGuest(): bool
    {
        return $this->via === self::GUEST;
    }

    /** Signed in with credentials (not a guest, not anonymous). */
    public function isUser(): bool
    {
        return $this->accountId !== null && in_array($this->via, [self::SESSION, self::BASIC, self::TOKEN], true);
    }

    public function failed(): bool
    {
        return $this->via === self::INVALID;
    }
}
