<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Application;
use App\Model\Recipe;
use App\Repository\JsonFileRecipeRepository;

abstract class AuthWebTestCase extends AuthTestCase
{
    protected Application $app;
    protected JsonFileRecipeRepository $recipes;
    protected string $recipeId;
    protected string $recipeSlug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeStandardDefaults();
        $this->recipes = new JsonFileRecipeRepository($this->dir . '/recipes.json');
        $recipe = $this->recipes->save(Recipe::create($this->recipes->nextId(), [
            'title' => 'Test Soup',
            'cooking_time' => 20,
            'ingredients' => [['quantity' => '1 l', 'name' => 'water']],
            'steps' => ['Boil.'],
        ]));
        $this->recipeId = $recipe->id;
        $this->recipeSlug = $recipe->slug;
        $this->app = $this->app();
    }

    /** @param array<string, mixed> $auth flat auth settings without the "auth_" prefix */
    protected function app(array $auth = []): Application
    {
        $config = [
            'auth_defaults_dir' => $this->dir . '/defaults',
            'auth_state_dir' => $this->dir . '/state',
            'auth_password_cost' => 4,
        ];
        foreach ($auth as $key => $value) {
            $config['auth_' . $key] = $value;
        }

        return new Application(
            $this->recipes,
            config: $config,
        );
    }

    protected function browser(string $ip = '10.0.0.1', bool $https = false, ?Application $app = null): Browser
    {
        return new Browser($app ?? $this->app, $ip, $https);
    }

    protected function loggedIn(string $user, string $password): Browser
    {
        $browser = $this->browser();
        $response = $browser->login($user, $password);
        self::assertSame(303, $response->status(), "login of $user failed");
        $browser->flashes();

        return $browser;
    }

    /** @return array<string, mixed> a valid recipe */
    protected function recipePayload(string $title = 'New Dish'): array
    {
        return ['title' => $title, 'cooking_time' => 10, 'ingredients' => ['salt'], 'steps' => ['Cook.']];
    }

    protected function flashMessages(Browser $browser): string
    {
        return implode(' | ', array_column($browser->flashes(), 'message'));
    }
}
