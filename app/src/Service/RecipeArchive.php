<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpException;
use InvalidArgumentException;
use JsonException;
use Throwable;
use ZipArchive;

/**
 * Writes the export as a ZIP archive with the referenced pictures and reads such an archive for the import.
 * The archive contains recipes.json in the same shape the JSON import accepts, plus the local image files
 * below images/ and uploads/.
 */
final class RecipeArchive
{
    private const JSON_ENTRY = 'recipes.json';
    private const MAX_JSON_BYTES = 10 * 1024 * 1024;
    private const MAX_ENTRIES = 2000;
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 512 * 1024 * 1024;

    public function __construct(private readonly ImageStorage $images)
    {
    }

    /**
     * @param  list<array<string, mixed>> $recipes the exported recipe objects
     * @return string path to a temporary ZIP file that the caller must stream and delete
     */
    public function write(array $recipes): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'The PHP zip extension is not installed.');
        }

        $file = tempnam(sys_get_temp_dir(), 'recipebox-export-');
        if ($file === false) {
            throw new HttpException(500, 'Could not create the export file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($file);
            throw new HttpException(500, 'Could not create the ZIP archive.');
        }

        try {
            if (!$zip->addFromString(
                self::JSON_ENTRY,
                json_encode(['recipes' => $recipes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            )) {
                throw new HttpException(500, 'Could not write recipes.json into the ZIP archive.');
            }

            $added = [];
            foreach ($recipes as $recipe) {
                $entry = $recipe['image'] ?? null;
                if (!is_string($entry) || $entry === '' || isset($added[$entry]) || ImageLocalizer::isRemote($entry)) {
                    continue;
                }

                $local = $this->localFile($entry);
                if ($local === null) {
                    continue;
                }
                if (!$zip->addFile($local, $entry)) {
                    throw new HttpException(500, 'Could not add an image to the ZIP archive.');
                }
                $added[$entry] = true;
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($file);
            throw $e;
        }

        if (!$zip->close()) {
            @unlink($file);
            throw new HttpException(500, 'Could not finish the ZIP archive.');
        }

        return $file;
    }

    /**
     * Reads recipes.json and stores the pictures it references, so the ordinary JSON import can attach them.
     *
     * @return array{recipes: list<mixed>, created: list<string>, warnings: list<string>}
     */
    public function read(string $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'The PHP zip extension is not installed.');
        }

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new HttpException(400, 'The uploaded file is not a ZIP archive.');
        }

        /** @var list<string> $created */
        $created = [];

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new HttpException(413, 'The archive contains too many files.');
            }

            $stat = $zip->statName(self::JSON_ENTRY);
            if ($stat === false) {
                throw new HttpException(400, 'The archive does not contain a recipes.json file.');
            }
            if ((int) ($stat['size'] ?? 0) > self::MAX_JSON_BYTES) {
                throw new HttpException(413, 'The recipes.json file in the archive is too large.');
            }

            $json = $zip->getFromName(self::JSON_ENTRY);
            if ($json === false) {
                throw new HttpException(400, 'The recipes.json file in the archive could not be read.');
            }

            try {
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException(400, 'The recipes.json file in the archive is not valid JSON.');
            }
            if (!is_array($payload)) {
                throw new HttpException(400, 'The recipes.json file in the archive does not contain recipes.');
            }

            $recipes = $this->recipeList($payload);
            $images = [];
            $warnings = [];
            $total = 0;

            foreach ($recipes as $index => &$recipe) {
                if (!is_array($recipe)) {
                    continue;
                }

                $entry = $recipe['image'] ?? null;
                if (!is_string($entry) || $entry === '' || ImageLocalizer::isRemote($entry) || !$this->isImageEntry($entry)) {
                    continue;
                }

                if (isset($images[$entry])) {
                    $recipe['image'] = $images[$entry];
                    continue;
                }

                // A path that is not in the archive stays untouched; the existing importer then warns about it.
                $stat = $zip->statName($entry);
                if ($stat === false) {
                    continue;
                }

                $size = (int) ($stat['size'] ?? 0);
                if ($size > self::MAX_IMAGE_BYTES) {
                    $warnings[] = 'recipe ' . ($index + 1) . ': image "' . $entry . '" is larger than 8 MB';
                    $recipe['image'] = '';
                    continue;
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new HttpException(413, 'The pictures in the archive are too large.');
                }

                $bytes = $zip->getFromName($entry);
                if ($bytes === false) {
                    $warnings[] = 'recipe ' . ($index + 1) . ': image "' . $entry . '" could not be read from the archive';
                    $recipe['image'] = '';
                    continue;
                }

                try {
                    $path = $this->images->storeImported($bytes);
                } catch (InvalidArgumentException $e) {
                    $warnings[] = 'recipe ' . ($index + 1) . ': image "' . $entry . '" could not be used: ' . $e->getMessage();
                    $recipe['image'] = '';
                    continue;
                }

                $images[$entry] = $path;
                $created[] = $path;
                $recipe['image'] = $path;
            }
            unset($recipe);

            return ['recipes' => $recipes, 'created' => $created, 'warnings' => $warnings];
        } catch (Throwable $e) {
            foreach (array_unique($created) as $path) {
                $this->images->remove($path);
            }
            throw $e;
        } finally {
            $zip->close();
        }
    }

    /** @return list<mixed> */
    private function recipeList(array $payload): array
    {
        if (isset($payload['recipes']) && is_array($payload['recipes'])) {
            return array_values($payload['recipes']);
        }

        return array_is_list($payload) ? $payload : [$payload];
    }

    /** The image file for a local path, or null when it is missing, remote or outside public/. */
    private function localFile(string $entry): ?string
    {
        if (!$this->isImageEntry($entry)) {
            return null;
        }

        $root = realpath($this->images->publicDir());
        $file = realpath($this->images->publicDir() . '/' . $entry);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }

        return str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }

    /** Whether a name is a file below images/ or uploads/, without traversal or directory entries. */
    private function isImageEntry(string $entry): bool
    {
        if ($entry === '' || str_contains($entry, '\\') || str_ends_with($entry, '/') || in_array('..', explode('/', $entry), true)) {
            return false;
        }

        return preg_match('#^(?:images|uploads)/.+$#', $entry) === 1;
    }
}
