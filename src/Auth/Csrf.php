<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Session;

/** One random token per session; forms send it as "_csrf", scripts as the X-CSRF-Token header. */
final class Csrf
{
    public function token(Session $session): string
    {
        $token = $session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('csrf', $token);
        }

        return $token;
    }

    public function rotate(Session $session): void
    {
        $session->set('csrf', bin2hex(random_bytes(32)));
    }

    public function isValid(Session $session, mixed $given): bool
    {
        $expected = $session->get('csrf');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
