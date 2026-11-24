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
use App\Service\ImageFetchException;
use App\Service\ImageLocalizer;
use App\Service\ImageStorage;
use App\Validation\RecipeValidator;

final class RecipeController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepositoryInterface $recipes,
        private readonly RecipeValidator $validator,
        private readonly ImageStorage $images,
        private readonly ImageLocalizer $localizer,
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
        $data = $this->validator->validate($request->json());
        $url = $this->checkedRemoteImage($data['image']);

        // a downloaded picture is named after the slug, which only exists once the recipe is saved
        $recipe = $this->recipes->save(Recipe::create($this->recipes->nextId(), $url === null ? $data : [...$data, 'image' => '']));

        $warnings = [];
        if ($url !== null) {
            [$path, $warning] = $this->localizer->localize($url, $recipe->slug);
            if ($path !== '') {
                $recipe = $this->recipes->save($recipe->withImage($path));
            } elseif ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $this->json($warnings === [] ? $recipe : [...$recipe->jsonSerialize(), 'warnings' => $warnings], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $existing = $this->findOrFail($id);
        $data = $this->validator->validate($request->json());

        // an unchanged value is left alone, so recipes that still contain a link can be edited while that site is down
        $url = $data['image'] === $existing->image ? null : $this->checkedRemoteImage($data['image']);
        if ($url !== null) {
            [$path, $warning] = $this->localizer->localize($url, $existing->slug);
            if ($path === '') {
                throw new ValidationException(['image' => (string) $warning]);
            }
            $data['image'] = $path;
        }

        $updated = $existing->withData($data);
        try {
            $this->recipes->save($updated);
        } catch (\Throwable $e) {
            if ($url !== null) {
                $this->images->remove($updated->image, $existing->slug);
            }
            throw $e;
        }

        if ($existing->image !== $updated->image) {
            $this->removeImageIfUnused($existing->image, $existing->slug);
        }

        return $this->json($updated);
    }

    public function destroy(Request $request, string $id): Response
    {
        $existing = $this->findOrFail($id);
        $this->recipes->delete($id);
        $this->removeImageIfUnused($existing->image, $existing->slug);

        return $this->noContent();
    }

    /**
     * Accepts a single recipe object, a list of recipes or {"recipes": [...]}. All or nothing for the
     * recipes; pictures from other servers are downloaded into this installation, and pictures that
     * cannot be fetched only produce warnings.
     */
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

        // Pictures are named after the slug, which the repository assigns when saving: save first, then attach pictures.
        $pictures = array_column($validated, 'image');
        $recipes = $this->recipes->saveMany(array_map(
            fn (array $data): Recipe => Recipe::create($this->recipes->nextId(), [...$data, 'image' => '']),
            $validated,
        ));
        // nextId() only checks stored recipes; the random id space makes batch collisions negligible

        $warnings = [];
        $withPicture = [];
        foreach ($recipes as $i => $recipe) {
            [$path, $warning] = $this->localizer->localize($pictures[$i], $recipe->slug);
            if ($warning !== null) {
                $warnings[] = 'recipe ' . ($i + 1) . ': ' . $warning;
            }
            if ($path !== '') {
                $withPicture[$i] = $recipe->withImage($path);
            }
        }

        if ($withPicture !== []) {
            try {
                $saved = $this->recipes->saveMany(array_values($withPicture));
            } catch (\Throwable $e) {
                foreach ($this->localizer->created() as $path => $slug) {
                    $this->images->remove($path, $slug);
                }
                throw $e;
            }
            foreach (array_keys($withPicture) as $n => $i) {
                $recipes[$i] = $saved[$n];
            }
        }

        return $this->json(['imported' => count($recipes), 'recipes' => $recipes, 'warnings' => $warnings], 201);
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

    /**
     * Returns the picture URL if the picture is on another server and can be downloaded, null for everything else.
     *
     * @throws ValidationException when the picture cannot be downloaded
     */
    private function checkedRemoteImage(string $image): ?string
    {
        if (!ImageLocalizer::isRemote($image)) {
            return null;
        }

        try {
            $this->localizer->check($image);
        } catch (ImageFetchException | \InvalidArgumentException $e) {
            throw new ValidationException(['image' => 'The picture could not be downloaded: ' . $e->getMessage() . '.']);
        }

        return $image;
    }

    private function findOrFail(string $id): Recipe
    {
        return $this->recipes->find($id) ?? throw new NotFoundException('Recipe not found');
    }

    /** @return array<string, mixed> the recipe in the same shape that the import accepts */
    private function exportable(Recipe $recipe): array
    {
        return array_diff_key($recipe->jsonSerialize(), array_flip(['id', 'slug', 'servings', 'created_at', 'updated_at']));
    }

    private function removeImageIfUnused(?string $image, string $ownerSlug): void
    {
        if ($image === null) {
            return;
        }
        foreach ($this->recipes->all() as $recipe) {
            if ($recipe->image === $image) {
                return;
            }
        }
        $this->images->remove($image, $ownerSlug);
    }
}
