<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Describes how every setting in defaults/config.php is edited in the administration.
 *
 * The first element of an entry is the control type; the optional "_..." keys refine it:
 *
 *     'storage' => ['multichoice', '_choices' => ['json' => 'json', 'sqlite' => 'sqlite'],
 *                   '_choice_labels' => ['json' => 'JSON file', 'sqlite' => 'SQLite database']],
 *     'auth_enabled' => ['onoff'],
 *     'llm_prompt' => ['textarea'],
 *     'base_url' => ['string', '_nullable' => true, '_allow_empty' => true],
 *
 * Settings that are not listed here still work: their control is inferred from the type of the
 * shipped default (bool = on/off, int = number, everything else = text). This keeps the generated
 * configuration page working when a new setting is added to defaults/config.php.
 */
final class ConfigSchema
{
    /** Groups of settings by key prefix; the empty prefix is the general group. */
    public const GROUPS = [
        '' => ['General', 'bi-gear'],
        'auth_' => ['Authentication and access control', 'bi-shield-lock'],
        'llm_' => ['Import from a URL (AI)', 'bi-stars'],
    ];

    /** Control type and presentation hints for the shipped settings. */
    public const META = [
        'base_url' => [
            'string', '_nullable' => true, '_allow_empty' => true,
            '_label' => 'Base URL',
            '_description' => 'URL prefix under which public/ is served. Leave it automatic unless a reverse proxy strips the prefix.',
        ],
        'storage' => [
            'multichoice',
            '_choices' => ['json' => 'json', 'sqlite' => 'sqlite'],
            '_choice_labels' => ['json' => 'JSON file', 'sqlite' => 'SQLite database'],
            '_label' => 'Storage backend',
            '_description' => 'Where recipes are stored. SQLite needs the pdo_sqlite extension. Switching does not copy existing recipes; use "php bin/migrate" first.',
        ],
        'json_file' => [
            'string',
            '_label' => 'JSON file name',
            '_description' => 'A bare file name inside var/state/. The directory is added automatically.',
        ],
        'import_allow_private_hosts' => [
            'onoff',
            '_label' => 'Allow private network hosts',
            '_description' => 'Permit image and page downloads from localhost and private addresses. Only enable this on a trusted network.',
        ],
        'llm_endpoint' => [
            'string',
            '_label' => 'LLM endpoint',
            '_description' => 'OpenAI-compatible chat completions URL (e.g. https://api.openai.com/v1/chat/completions). When empty, import from URL will be hidden.',
        ],
        'llm_api_key' => [
            'password',
            '_label' => 'LLM API key',
            '_description' => 'Sent as "Authorization: Bearer ..." to the endpoint. It never reaches the browser.',
        ],
        'llm_model' => [
            'string',
            '_label' => 'LLM model',
            '_description' => 'Model name sent to the endpoint, for example gpt-4o-mini.',
        ],
        'llm_prompt' => [
            'textarea',
            '_label' => 'LLM prompt',
            '_description' => '{html} is replaced with the page source and {url} with the address. Without {html} the HTML is appended.',
        ],
        'llm_timeout' => [
            'int', '_min' => 1,
            '_label' => 'LLM timeout (seconds)',
        ],
        'sqlite_file' => [
            'string',
            '_label' => 'SQLite file name',
            '_description' => 'A bare file name inside var/state/. The directory is added automatically.',
        ],
        'auth_enabled' => [
            'onoff',
            '_label' => 'Authentication enabled',
            '_description' => 'Turn the login off only on a trusted machine: everybody may then do everything, including the administration.',
        ],
        'auth_guest_account' => [
            'string',
            '_label' => 'Guest account',
            '_description' => 'Account used for visitors who are not signed in. Leave empty to require signing in.',
        ],
        'auth_authenticator' => [
            'string',
            '_label' => 'Authenticator provider',
            '_description' => 'Name of a provider registered in code. The shipped provider is "default".',
        ],
        'auth_authorizer' => [
            'string',
            '_label' => 'Authorizer provider',
            '_description' => 'Name of a provider registered in code. The shipped provider is "default".',
        ],
        'auth_defaults_dir' => [
            'string', '_nullable' => true,
            '_label' => 'Defaults directory',
            '_description' => 'Where the built-in accounts, roles and access table are read. Automatic uses defaults/.',
        ],
        'auth_state_dir' => [
            'string', '_nullable' => true,
            '_label' => 'State directory',
            '_description' => 'Where local account, role and access changes are stored. Automatic uses var/state/.',
        ],
        'auth_sessions_dir' => [
            'string', '_nullable' => true,
            '_label' => 'Sessions directory',
            '_description' => 'Where PHP stores its session files. Automatic uses var/sessions/.',
        ],
        'auth_session_name' => [
            'string',
            '_label' => 'Session cookie name',
        ],
        'auth_remember_cookie' => [
            'string',
            '_label' => 'Remember-me cookie name',
        ],
        'auth_remember_days' => [
            'int', '_min' => 1,
            '_label' => 'Remember-me duration (days)',
        ],
        'auth_cookie_samesite' => [
            'multichoice', '_choices' => ['Lax' => 'Lax', 'Strict' => 'Strict'],
            '_label' => 'SameSite policy',
        ],
        'auth_cookie_secure' => [
            'multichoice',
            '_choices' => ['auto' => 'auto', 'true' => true, 'false' => false],
            '_choice_labels' => ['auto' => 'Automatic', 'true' => 'Always', 'false' => 'Never'],
            '_label' => 'Secure cookie',
            '_description' => 'Automatic sends the cookie only over HTTPS. Always and Never force the setting.',
        ],
        'auth_min_password_length' => [
            'int', '_min' => 1,
            '_label' => 'Minimum password length',
        ],
        'auth_max_login_attempts' => [
            'int', '_min' => 1,
            '_label' => 'Maximum sign-in attempts',
            '_description' => 'Failed attempts per account and address before that combination is locked.',
        ],
        'auth_lockout_seconds' => [
            'int', '_min' => 0,
            '_label' => 'Lockout duration (seconds)',
        ],
    ];

    /**
     * Returns the control information for one setting. Unknown settings fall back to a control
     * derived from the type of their default, so new defaults need no change here.
     *
     * @return array{
     *     type: string,
     *     choices: list<array{key: string, label: string, value: mixed}>,
     *     nullable: bool,
     *     allow_empty: bool,
     *     min: ?int,
     *     max: ?int,
     *     label: string,
     *     description: string
     * }
     */
    public static function describe(string $key, mixed $default): array
    {
        $meta = self::META[$key] ?? [];
        $type = (string) ($meta[0] ?? self::infer($default));
        $choices = [];

        if ($type === 'multichoice') {
            $labels = (array) ($meta['_choice_labels'] ?? []);
            foreach ((array) ($meta['_choices'] ?? []) as $choiceKey => $choiceValue) {
                $choiceKey = is_int($choiceKey) ? (string) $choiceValue : (string) $choiceKey;
                $choices[] = [
                    'key' => $choiceKey,
                    'label' => (string) ($labels[$choiceKey] ?? $choiceKey),
                    'value' => $choiceValue,
                ];
            }
        }

        return [
            'type' => $type,
            'choices' => $choices,
            'nullable' => (bool) ($meta['_nullable'] ?? ($default === null)),
            'allow_empty' => (bool) ($meta['_allow_empty'] ?? false),
            'min' => isset($meta['_min']) ? (int) $meta['_min'] : null,
            'max' => isset($meta['_max']) ? (int) $meta['_max'] : null,
            'label' => (string) ($meta['_label'] ?? ucfirst(str_replace('_', ' ', $key))),
            'description' => (string) ($meta['_description'] ?? ''),
        ];
    }

    /**
     * Orders the given settings into the groups shown on the configuration page.
     *
     * @param  list<string> $keys
     * @return list<array{prefix: string, label: string, icon: string, keys: list<string>}>
     */
    public static function groups(array $keys): array
    {
        $groups = [];
        foreach (self::GROUPS as $prefix => [$label, $icon]) {
            $groups[$prefix] = ['prefix' => $prefix, 'label' => $label, 'icon' => $icon, 'keys' => []];
        }

        foreach ($keys as $key) {
            $key = (string) $key;
            $prefix = self::groupFor($key);
            if (!isset($groups[$prefix])) {
                $groups[$prefix] = ['prefix' => $prefix, 'label' => self::groupLabel($prefix), 'icon' => 'bi-gear', 'keys' => []];
            }
            $groups[$prefix]['keys'][] = $key;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => $group['keys'] !== []));
    }

    /** The group prefix for a setting: a known prefix or the general group. */
    public static function groupFor(string $key): string
    {
        foreach (array_keys(self::GROUPS) as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                return $prefix;
            }
        }

        return '';
    }

    /** A readable label for a group that is not listed in GROUPS. */
    public static function groupLabel(string $prefix): string
    {
        $name = trim($prefix, '_');

        return $name === '' ? 'General' : ucfirst(str_replace('_', ' ', $name)) . ' settings';
    }

    /** The control for a setting without an explicit entry, derived from its default value. */
    private static function infer(mixed $default): string
    {
        return match (true) {
            is_bool($default) => 'onoff',
            is_int($default) => 'int',
            is_array($default) => 'textarea',
            default => 'string',
        };
    }
}
