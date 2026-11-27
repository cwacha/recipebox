<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamedJsonResponse;
use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use App\Service\ImageFetchException;
use App\Service\ImageLocalizer;
use App\Service\ImageStorage;
use App\Service\RecipeArchive;
use App\Service\RecipeExtractor;
use App\Service\RecipeImportException;
use App\Validation\RecipeValidator;
use Closure;

final class RecipeController extends AbstractController
{
    private const MAX_ARCHIVE_BYTES = 512 * 1024 * 1024;

    public function __construct(
        private readonly RecipeRepositoryInterface $recipes,
        private readonly RecipeValidator $validator,
        private readonly ImageStorage $images,
        private readonly ImageLocalizer $localizer,
        private readonly RecipeArchive $archive,
        private readonly ?RecipeExtractor $extractor = null,
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
        return $this->importPayload($this->payloadRecipes($request->json()));
    }

    /** Imports a ZIP archive written by exportAll(): recipes.json plus the pictures it references. */
    public function importArchive(Request $request): Response
    {
        $replace = filter_var($request->post['replace'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($replace && !$this->canClearRecipes($request)) {
            throw new HttpException(403, 'You do not have permission to clear all recipes.');
        }

        $archive = $this->archive->read($this->archiveUpload($request));

        return $this->importPayload($archive['recipes'], $archive['created'], $archive['warnings'], $replace);
    }

    /**
     * @param list<mixed>  $recipes
     * @param list<string> $staged  pictures stored for an archive import, removed again when the import fails
     * @param list<string> $warnings warnings collected before the recipes are validated
     * @param bool         $replace  whether all existing recipes are removed first
     */
    private function importPayload(array $recipes, array $staged = [], array $warnings = [], bool $replace = false): Response
    {
        $previous = $replace ? $this->recipes->all() : [];
        $cleared = false;

        try {
            if ($recipes === []) {
                throw new HttpException(400, 'The import does not contain any recipes.');
            }
            if (count($recipes) > 200) {
                throw new HttpException(413, 'Too many recipes in one import (max 200).');
            }

            $errors = [];
            $validated = [];
            foreach ($recipes as $i => $item) {
                try {
                    $validated[] = $this->validator->validate(is_array($item) ? $item : [], 'recipe ' . ($i + 1) . ': ');
                } catch (ValidationException $e) {
                    $errors += $e->errors;
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            // Everything is valid: only now is it safe to remove the existing recipes. Their pictures are removed
            // after a successful import, so a failure can still restore the old recipes with their pictures.
            if ($replace) {
                $cleared = true;
                $this->clearRecipes();
            }

            // Pictures are named after the slug, which the repository assigns when saving: save first, then attach pictures.
            $pictures = array_column($validated, 'image');
            $savedRecipes = $this->recipes->saveMany(array_map(
                fn (array $data): Recipe => Recipe::create($this->recipes->nextId(), [...$data, 'image' => '']),
                $validated,
            ));
            // nextId() only checks stored recipes; the random id space makes batch collisions negligible

            $withPicture = [];
            foreach ($savedRecipes as $i => $recipe) {
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
                    $savedRecipes[$i] = $saved[$n];
                }
            }

            $response = $this->json(['imported' => count($savedRecipes), 'recipes' => $savedRecipes, 'warnings' => $warnings], 201);
        } catch (\Throwable $e) {
            if ($cleared) {
                try {
                    $this->restoreRecipes($previous);
                } catch (\Throwable $restoreError) {
                    error_log((string) $restoreError);
                }
            }
            foreach (array_unique($staged) as $path) {
                $this->images->remove($path);
            }
            throw $e;
        }

        if ($replace) {
            foreach ($previous as $recipe) {
                try {
                    $this->removeImageIfUnused($recipe->image, $recipe->slug);
                } catch (\Throwable $e) {
                    error_log((string) $e);
                }
            }
        }

        return $response;
    }

    private function canClearRecipes(Request $request): bool
    {
        $shared = $request->attribute('shared', []);
        $can = is_array($shared) ? ($shared['can'] ?? null) : null;

        return is_callable($can) && $can('recipe_delete');
    }

    private function clearRecipes(): void
    {
        foreach ($this->recipes->all() as $recipe) {
            if (!$this->recipes->delete($recipe->id)) {
                throw new HttpException(500, 'Could not clear the existing recipes.');
            }
        }
    }

    /**
     * Puts the recipes back after a failed replace import. Recipes created by the failed attempt are removed so
     * they cannot collide with the old slugs.
     *
     * @param list<Recipe> $previous
     */
    private function restoreRecipes(array $previous): void
    {
        $oldIds = array_fill_keys(array_map(static fn (Recipe $recipe): string => $recipe->id, $previous), true);

        foreach ($this->recipes->all() as $recipe) {
            if (!isset($oldIds[$recipe->id])) {
                $this->recipes->delete($recipe->id);
            }
        }

        if ($previous !== []) {
            $this->recipes->saveMany($previous);
        }
    }

    /**
     * One request for API clients: download the page, ask the model and return the preview. The import page
     * uses importPage() and importExtract() instead, so it can tick off the download before the slow model call.
     */
    public function importUrl(Request $request): Response
    {
        $url = $this->recipeUrl($request);
        $extractor = $this->extractor();
        [$html, $warning] = $this->downloadPage($extractor, $url);

        try {
            $recipe = $extractor->extract($html, $url);
        } catch (RecipeImportException $e) {
            throw new HttpException(502, 'The recipe could not be read from that URL: ' . $e->getMessage());
        }

        return $this->json(['recipe' => $recipe, 'url' => $url, 'warning' => $warning]);
    }

    /**
     * First half of the import page's URL import: download the page and keep it in the session until the model
     * has read it. This request is quick, so the browser can report the download as done before the model call
     * starts (a buffering proxy would otherwise hold back every progress event until the very end).
     */
    public function importPage(Request $request): Response
    {
        $url = $this->recipeUrl($request);
        $extractor = $this->extractor();
        [$html, $warning] = $this->downloadPage($extractor, $url);

        $token = bin2hex(random_bytes(16));
        $request->session()->set('import_page', [
            'token' => $token,
            'url' => $url,
            'html' => $html,
            'time' => time(),
        ]);

        return $this->json(['token' => $token, 'url' => $url, 'bytes' => strlen($html), 'warning' => $warning]);
    }

    /**
     * Second half: ask the model about the page downloaded by importPage(). The answer is streamed only so that
     * a cancelled import (the browser closes the connection) stops the request to the model; the page measures
     * the steps from the request boundaries and only needs the final event.
     */
    public function importExtract(Request $request): Response
    {
        $value = $request->json()['token'] ?? '';
        $token = is_scalar($value) ? (string) $value : '';
        $extractor = $this->extractor();

        $session = $request->session();
        $page = $session->get('import_page');
        $session->remove('import_page');
        $session->close(); // the model may take a minute; other requests of this browser must not wait for it

        if (!is_array($page)
            || !is_string($page['token'] ?? null)
            || !is_string($page['url'] ?? null)
            || !is_string($page['html'] ?? null)
            || !is_int($page['time'] ?? null)
            || !hash_equals($page['token'], $token)
            || time() - $page['time'] > 600
        ) {
            throw new HttpException(409, 'The downloaded page is no longer available. Please fetch the recipe again.');
        }

        return new StreamedJsonResponse(function (Closure $emit) use ($extractor, $page): void {
            $this->streamExtraction($extractor, $page['html'], $page['url'], $emit);
        });
    }

    /**
     * Downloads the page for the model. A failure is not fatal: the model gets the URL instead, because some
     * models can open pages themselves (bot protection often answers 403 to the application, for example).
     *
     * @return array{string, ?string} the HTML ('' when the download failed) and a warning for the user
     */
    private function downloadPage(RecipeExtractor $extractor, string $url): array
    {
        try {
            return [$extractor->fetch($url), null];
        } catch (RecipeImportException $e) {
            $detail = trim(rtrim($e->getMessage(), '.')) ?: 'unknown error';

            return ['', 'The page could not be downloaded: ' . $detail
                . '. Asking the model to open the URL itself; this only works if the model can browse the web.'];
        }
    }

    /** The URL from the request body, validated for a page that may be downloaded. */
    private function recipeUrl(Request $request): string
    {
        $value = $request->json()['url'] ?? '';
        $url = is_scalar($value) ? trim((string) $value) : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || mb_strlen($url) > 2000
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
            || preg_match('/[\x00-\x20]/', $url) === 1
        ) {
            throw new ValidationException(['url' => 'Enter a valid http(s) recipe URL.']);
        }

        return $url;
    }

    private function extractor(): RecipeExtractor
    {
        return $this->extractor ?? throw new HttpException(503, 'Importing a recipe from a URL is not configured.');
    }

    /**
     * Asks the model for the recipe and streams the result. Errors become events as well, because the HTTP
     * headers have already been sent by the time the work starts.
     *
     * @param Closure(array<string, mixed>): void $emit
     */
    private function streamExtraction(RecipeExtractor $extractor, string $html, string $url, Closure $emit): void
    {
        // The browser closes this connection when the import is cancelled. A heartbeat while the model works makes
        // PHP notice that, and the request to the model is aborted instead of running to its end unseen.
        $cancelled = static function () use ($emit): bool {
            $emit(['event' => 'ping']);

            return connection_aborted() === 1;
        };

        try {
            $recipe = $extractor->extract($html, $url, $cancelled);
            $emit(['event' => 'done', 'recipe' => $recipe, 'url' => $url]);
        } catch (RecipeImportException $e) {
            $emit(['event' => 'error', 'message' => 'The recipe could not be read from that URL: ' . $e->getMessage()]);
        } catch (ValidationException $e) {
            $emit(['event' => 'error', 'message' => 'The model did not answer with a complete recipe.', 'errors' => $e->errors]);
        } catch (\Throwable $e) {
            error_log((string) $e);
            $emit(['event' => 'error', 'message' => 'Internal server error.']);
        }
    }

    public function export(Request $request, string $idOrSlug): Response
    {
        $recipe = $this->recipes->find($idOrSlug) ?? $this->recipes->findBySlug($idOrSlug) ?? throw new NotFoundException('Recipe not found');
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($recipe->title)), '-');

        return $this->download($this->exportable($recipe), ($slug ?: 'recipe') . '.json');
    }

    public function exportAll(Request $request): Response
    {
        $recipes = array_map($this->exportable(...), $this->recipes->all());

        return new FileResponse($this->archive->write($recipes), 'recipes.zip', 'application/zip');
    }

    /** @return list<mixed> a single recipe object, a list of recipes or {"recipes": [...]} */
    private function payloadRecipes(array $payload): array
    {
        if (isset($payload['recipes']) && is_array($payload['recipes'])) {
            return array_values($payload['recipes']);
        }

        return array_is_list($payload) ? $payload : [$payload];
    }

    /** @return string the temporary file of the uploaded archive */
    private function archiveUpload(Request $request): string
    {
        $file = $request->files['archive'] ?? null;
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(413, 'The archive is too large.');
        }

        $tmp = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw new HttpException(400, 'No archive was uploaded.');
        }
        if (($file['size'] ?? filesize($tmp)) > self::MAX_ARCHIVE_BYTES) {
            throw new HttpException(413, 'The archive is larger than 512 MB.');
        }

        return $tmp;
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
