<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Application;
use App\Auth\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;

/** A pretend browser: keeps one session and a cookie jar between requests. */
final class Browser
{
    public readonly Session $session;
    /** @var array<string, string> */
    public array $cookies = [];
    public ?Response $last = null;

    public function __construct(
        private readonly Application $app,
        private readonly string $ip = '10.0.0.1',
        private readonly bool $https = false,
    ) {
        $this->session = Session::memory();
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $query = [], array $post = [], array $headers = [], string $body = ''): Response
    {
        $request = new Request(
            $method,
            $path,
            '',
            $query,
            $body,
            [],
            $post,
            $this->cookies,
            array_change_key_case($headers, CASE_LOWER),
            ['REMOTE_ADDR' => $this->ip, 'HTTPS' => $this->https ? 'on' : ''],
            $this->session,
        );

        $response = $this->app->handle($request);
        foreach ($response->cookies() as $cookie) {
            if ($cookie->value === '') {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }

        return $this->last = $response;
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $path, $query, headers: $headers);
    }

    /** A form post with the CSRF token of this browser's session. @param array<string, mixed> $fields */
    public function post(string $path, array $fields = [], bool $withToken = true): Response
    {
        return $this->request('POST', $path, post: $fields + ($withToken ? ['_csrf' => $this->token()] : []));
    }

    /**
     * A JSON API call, the way our scripts make it.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function json(string $method, string $path, ?array $data = null, array $headers = [], bool $withToken = true): Response
    {
        $headers += ['content-type' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'];
        if ($withToken) {
            $headers += ['x-csrf-token' => $this->token()];
        }

        return $this->request($method, $path, headers: $headers, body: $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function login(string $user, string $password, bool $remember = false, string $next = ''): Response
    {
        $fields = ['username' => $user, 'password' => $password, 'next' => $next];
        if ($remember) {
            $fields['remember'] = '1';
        }

        return $this->post('/login', $fields);
    }

    public function token(): string
    {
        return (new Csrf())->token($this->session);
    }

    /** @return array<string, mixed> */
    public static function data(Response $response): array
    {
        return json_decode($response->body(), true) ?? [];
    }

    public static function location(Response $response): ?string
    {
        return $response->headers()['Location'] ?? null;
    }

    /** @return list<array{type: string, message: string}> and removes them like a page view would */
    public function flashes(): array
    {
        return $this->session->pullFlash();
    }

    public function account(): ?string
    {
        return $this->session->get('account');
    }
}
