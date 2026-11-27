<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamedJsonResponse;
use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use App\Service\ImageFetchException;
use App\Service\ImageLocalizer;
use App\Service\ImageStorage;
use App\Service\RecipeExtractor;
use App\Service\RecipeImportException;
use App\Validation\RecipeValidator;
use Closure;

final class RecipeController extends AbstractController
{
    public function __construct(
        private readonly RecipeRepositoryInterface $recipes,
        private readonly RecipeValidator $validator,
        private readonly ImageStorage $images,
        private readonly ImageLocalizer $localizer,
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
