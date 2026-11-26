<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Server-side session. The native variant only starts PHP's session when something is written or the browser
 * already sent a session cookie, so anonymous visitors do not get one for nothing. The memory variant keeps
 * the data in the object (used by tests, where it plays the role of one browser).
 */
final class Session
{
    private bool $started = false;
    /** @var array<string, mixed> */
    private array $memory = [];
    private int $regenerated = 0;

    /**
     * @param array<string, mixed> $options name, save_path, path, secure, samesite
     * @param array<string, mixed> $cookies cookies of the request
     */
    private function __construct(private readonly bool $native, private readonly array $options = [], private readonly array $cookies = [])
    {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $cookies
     */
    public static function native(array $options, array $cookies): self
    {
        return new self(true, $options, $cookies);
    }

    public static function memory(): self
    {
        return new self(false);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->open(false)) {
            return $default;
        }

        return $this->native ? ($_SESSION[$key] ?? $default) : ($this->memory[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->open(true);
        if ($this->native) {
            $_SESSION[$key] = $value;
        } else {
            $this->memory[$key] = $value;
        }
    }

    public function remove(string $key): void
    {
        if ($this->open(false)) {
            if ($this->native) {
                unset($_SESSION[$key]);
            } else {
                unset($this->memory[$key]);
            }
        }
    }

    /** Gives the session a new ID (after sign-in), keeping its data. */
    public function regenerate(): void
    {
        $this->open(true);
        if ($this->native) {
            session_regenerate_id(true);
        }
        ++$this->regenerated;
    }

    /** How often the session ID was renewed (lets tests check that signing in does it). */
    public function regenerations(): int
    {
        return $this->regenerated;
    }

    /** Forgets everything, including the ID (sign-out). */
    public function clear(): void
    {
        if (!$this->open(false)) {
            return;
        }
        if ($this->native) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $this->memory = [];
        }
    }

    /** Writes the session and releases its lock, so a long request does not block other requests of this browser. */
    public function close(): void
    {
        if ($this->native && $this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function flash(string $type, string $message): void
    {
        $messages = $this->get('flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('flash', $messages);
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlash(): array
    {
        $messages = $this->get('flash', []);
        if ($messages !== []) {
            $this->remove('flash');
        }

        return $messages;
    }

    private function open(bool $forWrite): bool
    {
        if (!$this->native || $this->started) {
            return true;
        }
        if (!$forWrite && !isset($this->cookies[$this->options['name']])) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $this->started = true;
        }

        $dir = (string) ($this->options['save_path'] ?? '');
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if ($dir !== '') {
            session_save_path($dir);
        }
        session_name((string) $this->options['name']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) ($this->options['path'] ?? '/'),
            'secure' => (bool) ($this->options['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($this->options['samesite'] ?? 'Lax'),
        ]);

        return $this->started = session_start();
    }
}
