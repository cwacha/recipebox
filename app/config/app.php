<?php

declare(strict_types=1);

$state = dirname(__DIR__) . '/var/state';

$llmPrompt = \App\Service\LlmRecipeExtractor::DEFAULT_PROMPT;

return [
    // Filesystem root of the application (var/, defaults/, templates/, public/). Defaults to the folder this
    // file is in. Override it with RECIPES_BASE_DIR when the code and the writable data live apart.
    'base_dir' => getenv('RECIPES_BASE_DIR') ?: dirname(__DIR__),

    // URL under which public/ is served: '' for a document root, or a prefix such as
    // "/~user/recipebox/public". null (the default) detects it from the request, which is what you want
    // for the PHP built-in server and normal Apache/nginx setups. Set RECIPES_BASE_URL to make it explicit,
    // e.g. behind a reverse proxy that strips a prefix.
    'base_url' => getenv('RECIPES_BASE_URL') === false ? null : rtrim((string) getenv('RECIPES_BASE_URL'), '/'),

    // Persistence backend: "json" (single file) or "sqlite" (database, needs the pdo_sqlite extension).
    // Use "php bin/migrate json sqlite" to copy existing recipes when switching.
    'storage' => getenv('RECIPES_STORAGE') ?: 'json',

    'json_file' => getenv('RECIPES_STATE_FILE') ?: $state . '/recipes.json',
    // JSON import downloads pictures from other servers into public/images, and the AI import downloads the recipe
    // page itself. For safety, addresses inside private networks (localhost, 192.168.x.x, ...) are refused;
    // set to true to import from your own network.
    'import_allow_private_hosts' => filter_var(getenv('RECIPES_IMPORT_ALLOW_PRIVATE_HOSTS'), FILTER_VALIDATE_BOOLEAN),

    // Importing a recipe from a URL through an external LLM. The feature appears on the import page when
    // "endpoint" is set. It speaks the OpenAI-compatible chat completions format:
    // POST {"model": ..., "messages": [{"role": "user", "content": ...}]} with "Authorization: Bearer <key>".
    // The application downloads the page itself (with the same safety rules as image import) and puts its HTML
    // into the prompt at {html}; if the placeholder is missing the HTML is appended. {url} is replaced too.
    // The key never reaches the browser.
    'llm' => [
        'endpoint' => getenv('RECIPES_LLM_ENDPOINT') ?: '',
        'api_key' => getenv('RECIPES_LLM_API_KEY') ?: '',
        'model' => getenv('RECIPES_LLM_MODEL') ?: '',
        'prompt' => getenv('RECIPES_LLM_PROMPT') ?: $llmPrompt,
        'timeout' => (int) (getenv('RECIPES_LLM_TIMEOUT') ?: 60),
    ],

    'sqlite_file' => getenv('RECIPES_SQLITE_FILE') ?: $state . '/recipes.sqlite',

    'auth' => [
        // false switches the login off: everybody may do everything (including the administration!)
        'enabled' => filter_var(getenv('RECIPES_AUTH') === false ? 'true' : getenv('RECIPES_AUTH'), FILTER_VALIDATE_BOOLEAN),

        // Account used for visitors who are not signed in. It only gets the rights of its roles (default: read only).
        // Empty = guests are not allowed, everybody has to sign in.
        'guest_account' => getenv('RECIPES_GUEST_ACCOUNT') === false ? 'guest' : (string) getenv('RECIPES_GUEST_ACCOUNT'),

        // Providers are chosen by name. More can be registered with 'authenticators' / 'authorizers'
        // => ['name' => fn (array $authConfig) => new MyProvider(...)]
        'authenticator' => 'default',
        'authorizer' => 'default',

        // Built-in accounts/roles/access ship in defaults/, local changes are stored as differences in var/state/.
        // 'defaults_dir' => ..., 'state_dir' => ..., 'sessions_dir' => ...

        'session_name' => 'recipebox_session',
        'remember_cookie' => 'recipebox_remember',
        'remember_days' => 30,
        'cookie_samesite' => 'Lax',   // Lax | Strict
        'cookie_secure' => 'auto',    // 'auto' = only over HTTPS, or true / false
        'min_password_length' => 8,
        'max_login_attempts' => 5,    // per account and address, then locked for 'lockout_seconds'
        'lockout_seconds' => 900,
    ],
];
