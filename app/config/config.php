<?php

declare(strict_types=1);

/*
 * Local configuration. Every value here overrides the shipped defaults in defaults/config.php.
 * Only add the settings you want to change; the defaults stay in place for everything else.
 *
 * The settings below read the same environment variables as before and use the default when unset.
 * All keys are flat; nested sections use a prefix, e.g. llm_endpoint or auth_enabled.
 */

return [
    'base_url' => getenv('RECIPES_BASE_URL') === false ? null : rtrim((string) getenv('RECIPES_BASE_URL'), '/'),
    'storage' => getenv('RECIPES_STORAGE') ?: 'json',
    'json_file' => getenv('RECIPES_STATE_FILE') ?: 'recipes.json',
    'import_allow_private_hosts' => filter_var(getenv('RECIPES_IMPORT_ALLOW_PRIVATE_HOSTS'), FILTER_VALIDATE_BOOLEAN),

    'llm_endpoint' => getenv('RECIPES_LLM_ENDPOINT') ?: '',
    'llm_api_key' => getenv('RECIPES_LLM_API_KEY') ?: '',
    'llm_model' => getenv('RECIPES_LLM_MODEL') ?: '',
    'llm_prompt' => getenv('RECIPES_LLM_PROMPT') ?: \App\Service\LlmRecipeExtractor::DEFAULT_PROMPT,
    'llm_timeout' => (int) (getenv('RECIPES_LLM_TIMEOUT') ?: 60),

    'sqlite_file' => getenv('RECIPES_SQLITE_FILE') ?: 'recipes.sqlite',

    'auth_enabled' => filter_var(getenv('RECIPES_AUTH') === false ? 'true' : getenv('RECIPES_AUTH'), FILTER_VALIDATE_BOOLEAN),
    'auth_guest_account' => getenv('RECIPES_GUEST_ACCOUNT') === false ? 'guest' : (string) getenv('RECIPES_GUEST_ACCOUNT'),
];
