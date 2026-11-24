<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use App\View\View;

/** Server rendered HTML pages. Each page has its own URL. */
final class PageController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepositoryInterface $recipes,
        private readonly string $templateDir,
    ) {
    }

    public function home(Request $request): Response
    {
        $q = trim($request->query('q'));
        $category = trim($request->query('category'));

        return $this->html($this->view($request)->page('home', [
            'q' => $q,
            'category' => $category,
            'terms' => preg_split('/\s+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'recipes' => $this->recipes->search($q, $category ?: null),
            'categories' => $this->recipes->categories(),
        ], 'Recipe Box – your kitchen companion', 'home', ['home.js']));
    }

    public function show(Request $request, string $slug): Response
    {
        $recipe = $this->recipes->findBySlug($slug);
        if ($recipe === null) {
            return $this->redirectLegacyId($request, $slug, '');
        }

        return $this->html($this->view($request)->page(
            'recipe',
            ['recipe' => $recipe],
            $recipe->title . ' – Recipe Box',
            '',
            ['recipe.js'],
            $recipe->description !== '' ? $recipe->description : $recipe->title,
        ));
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, string $slug): Response
    {
        $recipe = $this->recipes->findBySlug($slug);

        return $recipe === null ? $this->redirectLegacyId($request, $slug, '/edit') : $this->form($request, $recipe);
    }

    public function import(Request $request): Response
    {
        return $this->html($this->view($request)->page('import', [
            'example' => [
                'title' => 'Tomato Bruschetta',
                'description' => 'Crisp toasted bread with marinated tomatoes.',
                'category' => 'Starter',
                'image' => 'https://example.com/bruschetta.jpg',
                'cooking_time' => 15,
                'ingredients' => [
                    ['quantity' => '8 slices', 'name' => 'ciabatta'],
                    ['quantity' => '500 g', 'name' => 'ripe tomatoes, diced'],
                    ['quantity' => '2 cloves', 'name' => 'garlic'],
                ],
                'steps' => ['Toast the bread.', 'Mix tomatoes, oil, salt and basil.', 'Rub the toast with garlic and top with the tomatoes.'],
                'tags' => ['italian', 'vegetarian'],
            ],
        ], 'Import recipes – Recipe Box', 'import', ['import.js']));
    }

    private function form(Request $request, ?Recipe $recipe): Response
    {
        return $this->html($this->view($request)->page('form', [
            'recipe' => $recipe,
            'categories' => $this->recipes->categories(),
        ], ($recipe ? 'Edit ' . $recipe->title : 'New recipe') . ' – Recipe Box', $recipe ? '' : 'new', ['form.js']));
    }

    /** Bookmarks made when URLs still contained the id keep working: they are redirected to the readable URL. */
    private function redirectLegacyId(Request $request, string $key, string $suffix): Response
    {
        $recipe = $this->recipes->find($key) ?? throw new NotFoundException('This recipe does not exist (anymore).');

        return $this->redirect($request->basePath . '/recipes/' . $recipe->slug . $suffix);
    }

    private function view(Request $request): View
    {
        return View::forRequest($this->templateDir, $request);
    }
}
