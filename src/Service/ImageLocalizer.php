<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Makes an imported recipe self contained: pictures that are not already files of this installation
 * are downloaded into public/images and named after the recipe's slug. Problems never abort an import;
 * the recipe simply gets no picture and a warning is reported instead.
 */
final class ImageLocalizer
{
    /** @var array<string, string|ImageFetchException> downloaded bytes (or the failure) per URL */
    private array $downloads = [];
    /** @var array<string, string> created file => slug of the recipe it belongs to */
    private array $created = [];
    private ?float $started = null;

    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly ImageStorage $storage,
        private readonly string $publicDir,
        private readonly float $budgetSeconds = 90.0,
    ) {
    }

    /**
     * @param  string $image the image value of the imported recipe
     * @param  string $slug  slug of the saved recipe, used as file name
     * @return array{string, ?string} the local image path ('' for none) and an optional warning
     */
    public function localize(string $image, string $slug): array
    {
        if ($image === '') {
            return ['', null];
        }

        return self::isRemote($image) ? $this->download($image, $slug) : $this->local($image);
    }

    public static function isRemote(string $image): bool
    {
        return preg_match('#^https?://#i', $image) === 1;
    }

    /**
     * Downloads and verifies a picture without storing it, so that a form can be rejected before anything is saved.
     * The download is kept, so a following localize() for the same URL does not fetch it again.
     *
     * @throws ImageFetchException|InvalidArgumentException with a message that can be shown to the user
     */
    public function check(string $url): void
    {
        $this->storage->assertImage($this->bytes($url));
    }

    /** @return array<string, string> files created by this import and the slug of their recipe (to clean up if it fails) */
    public function created(): array
    {
        return $this->created;
    }

    /** @return array{string, ?string} */
    private function local(string $path): array
    {
        return is_file($this->publicDir . '/' . $path)
            ? [$path, null]
            : ['', sprintf('image "%s" does not exist on this server, skipped', $path)];
    }

    /** @return array{string, ?string} */
    private function download(string $url, string $slug): array
    {
        try {
            $bytes = $this->bytes($url);
            $path = $this->storage->storeDownloaded($bytes, $slug);
        } catch (ImageFetchException | InvalidArgumentException | RuntimeException $e) {
            return ['', sprintf('image "%s" could not be downloaded: %s', $url, $e->getMessage())];
        }

        $this->created[$path] = $slug;

        return [$path, null];
    }

    /** @throws ImageFetchException */
    private function bytes(string $url): string
    {
        if (!isset($this->downloads[$url])) {
            if ($this->started === null) {
                $this->started = microtime(true);
                @set_time_limit((int) ceil($this->budgetSeconds) + 30);
            }

            try {
                $this->downloads[$url] = microtime(true) - $this->started > $this->budgetSeconds
                    ? new ImageFetchException('skipped, the import took too long')
                    : $this->fetcher->fetch($url);
            } catch (ImageFetchException $e) {
                $this->downloads[$url] = $e;
            }
        }

        $result = $this->downloads[$url];

        return $result instanceof ImageFetchException ? throw $result : $result;
    }
}
