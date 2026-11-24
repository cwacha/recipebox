<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use App\Service\ImageStorage;
use App\Validation\RecipeValidator;

final class RecipeController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepositoryInterface $recipes,
        private readonly RecipeValidator $validator,
        private readonly ImageStorage $images,
    ) {
    }

    public function index(Request $request): Response
    {
        $found = $this->recipes->search($request->query('q'), $request->query('category') ?: null);

        return $this->json([
            'recipes' => $found,
            'total' => count($found),
            'categories' => $this->recipes->categories(),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return $this->json($this->findOrFail($id));
    }

    public function store(Request $request): Response
    {
        $recipe = Recipe::create($this->recipes->nextId(), $this->validator->validate($request->json()));

        return $this->json($this->recipes->save($recipe), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $existing = $this->findOrFail($id);
        $updated = $existing->withData($this->validator->validate($request->json()));
        $this->recipes->save($updated);

        if ($existing->image !== $updated->image) {
            $this->removeImageIfUnused($existing->image);
        }

        return $this->json($updated);
    }

    public function destroy(Request $request, string $id): Response
    {
        $existing = $this->findOrFail($id);
        $this->recipes->delete($id);
        $this->removeImageIfUnused($existing->image);

        return $this->noContent();
    }

    /** Accepts a single recipe object, a list of recipes or {"recipes": [...]}. All or nothing. */
    public function import(Request $request): Response
    {
        $payload = $request->json();
        if (isset($payload['recipes']) && is_array($payload['recipes'])) {
            $payload = $payload['recipes'];
        } elseif (!array_is_list($payload)) {
            $payload = [$payload];
        }
        if ($payload === []) {
            throw new HttpException(400, 'The JSON does not contain any recipes.');
        }
        if (count($payload) > 200) {
            throw new HttpException(413, 'Too many recipes in one import (max 200).');
        }

        $errors = [];
        $validated = [];
        foreach ($payload as $i => $item) {
            try {
                $validated[] = $this->validator->validate(is_array($item) ? $item : [], 'recipe ' . ($i + 1) . ': ');
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $recipes = [];
        foreach ($validated as $data) {
            $recipes[] = Recipe::create($this->recipes->nextId(), $data);
            // nextId() only checks stored recipes; the random id space makes batch collisions negligible
        }
        $this->recipes->saveMany($recipes);

        return $this->json(['imported' => count($recipes), 'recipes' => $recipes], 201);
    }

    public function export(Request $request, string $id): Response
    {
        $recipe = $this->findOrFail($id);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($recipe->title)), '-');

        return $this->download($this->exportable($recipe), ($slug ?: 'recipe') . '.json');
    }

    public function exportAll(Request $request): Response
    {
        return $this->download(
            ['recipes' => array_map($this->exportable(...), $this->recipes->all())],
            'recipes.json',
        );
    }

    private function findOrFail(string $id): Recipe
    {
        return $this->recipes->find($id) ?? throw new NotFoundException('Recipe not found');
    }

    /** @return array<string, mixed> the recipe in the same shape that the import accepts */
    private function exportable(Recipe $recipe): array
    {
        return array_diff_key($recipe->jsonSerialize(), array_flip(['id', 'servings', 'created_at', 'updated_at']));
    }

    private function removeImageIfUnused(?string $image): void
    {
        if ($image === null) {
            return;
        }
        foreach ($this->recipes->all() as $recipe) {
            if ($recipe->image === $image) {
                return;
            }
        }
        $this->images->remove($image);
    }
}
