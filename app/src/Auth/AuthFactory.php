<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contract\AuthenticatorInterface;
use App\Auth\Contract\AuthorizerInterface;
use InvalidArgumentException;

/**
 * Builds the security controller from the "auth" configuration. Providers are chosen by name from a registry
 * (never by a path or class name taken from a request). Further providers can be registered in the
 * configuration under "authenticators" / "authorizers" as name => callable(array $authConfig): provider.
 */
final class AuthFactory
{
    /**
     * @param  array<string, mixed> $config the "auth" section of config/app.php
     * @return array<string, mixed> with all defaults filled in
     */
    public static function normalize(array $config, string $rootDir): array
    {
        return $config + [
            'enabled' => true,
            'guest_account' => 'guest',
            'authenticator' => 'default',
            'authorizer' => 'default',
            'defaults_dir' => $rootDir . '/defaults',
            'state_dir' => $rootDir . '/var/state',
            'sessions_dir' => $rootDir . '/var/sessions',
            'session_name' => 'recipebox_session',
            'remember_cookie' => 'recipebox_remember',
            'remember_days' => 30,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => 'auto',
            'min_password_length' => 8,
            'password_cost' => null,
            'max_login_attempts' => 5,
            'lockout_seconds' => 900,
        ];
    }

    /** @param array<string, mixed> $config normalized "auth" configuration */
    public static function create(array $config): SecurityController
    {
        $authenticators = ['default' => self::defaultAuthenticator(...)] + (array) ($config['authenticators'] ?? []);
        $authorizers = ['default' => self::defaultAuthorizer(...)] + (array) ($config['authorizers'] ?? []);

        $authenticator = self::provider($authenticators, (string) $config['authenticator'], $config, AuthenticatorInterface::class);
        $authorizer = self::provider($authorizers, (string) $config['authorizer'], $config, AuthorizerInterface::class);

        $security = new SecurityController($authenticator, $authorizer, (bool) $config['enabled']);
        $security->init();

        return $security;
    }

    /** @param array<string, mixed> $config */
    private static function defaultAuthenticator(array $config): AuthenticatorDefault
    {
        $guest = (string) ($config['guest_account'] ?? '');

        return new AuthenticatorDefault(
            self::store($config, 'accounts'),
            $guest === '' ? null : $guest,
            new TokenStore(new JsonFile($config['state_dir'] . '/auth_tokens.json')),
            (int) $config['min_password_length'],
            $config['password_cost'] === null ? null : (int) $config['password_cost'],
        );
    }

    /** @param array<string, mixed> $config */
    private static function defaultAuthorizer(array $config): AuthorizerDefault
    {
        return new AuthorizerDefault(self::store($config, 'roles'), self::store($config, 'access'));
    }

    /** @param array<string, mixed> $config */
    private static function store(array $config, string $name): LayeredStore
    {
        return new LayeredStore(
            new JsonFile($config['defaults_dir'] . '/' . $name . '.json'),
            new JsonFile($config['state_dir'] . '/' . $name . '.json'),
        );
    }

    /**
     * @param  array<string, callable> $registry
     * @param  array<string, mixed>    $config
     * @param  class-string            $contract
     */
    private static function provider(array $registry, string $name, array $config, string $contract): object
    {
        if (!isset($registry[$name])) {
            throw new InvalidArgumentException(sprintf('Unknown provider "%s". Available: %s.', $name, implode(', ', array_keys($registry))));
        }

        $provider = $registry[$name]($config);
        if (!$provider instanceof $contract) {
            throw new InvalidArgumentException(sprintf('Provider "%s" must implement %s.', $name, $contract));
        }

        return $provider;
    }
}
