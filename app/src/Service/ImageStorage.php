<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpException;
use InvalidArgumentException;
use RuntimeException;

/** Stores uploaded and imported images below public/uploads and downloaded images below public/images. */
final class ImageStorage
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    public const PREFIX = 'uploads/';
    private const DOWNLOAD_DIR = 'images';

    public function __construct(
        private readonly string $publicDir = BASEDIR . '/public',
        private readonly string $defaultsDir = BASEDIR . '/defaults/seed',
    ) {
    }

    /** The document root that relative image paths such as "images/x.jpg" refer to. */
    public function publicDir(): string
    {
        return $this->publicDir;
    }

    /**
     * @param  array<string, mixed> $file an entry of $_FILES
     * @return string path relative to the public directory, e.g. "uploads/ab12.jpg"
     */
    public function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(413, 'The image is too large.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new HttpException(400, 'No image was uploaded.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpException(413, 'The image is larger than 8 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new HttpException(415, 'Only JPEG, PNG, WebP and GIF images are allowed.');
        }

        $dir = $this->publicDir . '/' . self::PREFIX;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Upload directory is not writable.');
        }

        $name = bin2hex(random_bytes(10)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
            throw new HttpException(500, 'Could not save the image.');
        }

        return self::PREFIX . $name;
    }

    /**
     * Stores an image that was downloaded during an import in the public images folder, named after the
     * recipe it belongs to ("images/<slug>.jpg"). An existing file is never overwritten; the name then gets
     * a number ("<slug>-2.jpg").
     *
     * @return string path relative to the public directory, e.g. "images/spaghetti-carbonara.jpg"
     * @throws InvalidArgumentException when the data is not a supported image
     */
    public function storeDownloaded(string $bytes, string $slug): string
    {
        $extension = $this->assertImage($bytes);

        $dir = $this->publicDir . '/' . self::DOWNLOAD_DIR . '/';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('the image folder is not writable');
        }

        for ($n = 1; $n < 1000; $n++) {
            $name = $slug . ($n > 1 ? '-' . $n : '') . '.' . $extension;
            // mode "x" creates the file atomically and fails if it exists, so concurrent imports cannot collide
            $handle = @fopen($dir . $name, 'xb');
            if ($handle === false) {
                continue;
            }

            $written = fwrite($handle, $bytes);
            fclose($handle);
            if ($written !== strlen($bytes)) {
                @unlink($dir . $name);
                throw new RuntimeException('the image could not be saved');
            }

            return self::DOWNLOAD_DIR . '/' . $name;
        }

        throw new RuntimeException('no free file name for the image');
    }

    /**
     * Stores picture bytes from an import archive in the uploads folder with a random name, so an archive can
     * never overwrite an existing file.
     *
     * @return string path relative to the public directory, e.g. "uploads/ab12cd34ef.jpg"
     * @throws InvalidArgumentException when the data is not a supported image
     */
    public function storeImported(string $bytes): string
    {
        $extension = $this->assertImage($bytes);

        $dir = $this->publicDir . '/' . self::PREFIX;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('the upload folder is not writable');
        }

        $name = bin2hex(random_bytes(10)) . '.' . $extension;
        $handle = @fopen($dir . $name, 'xb');
        if ($handle === false) {
            throw new RuntimeException('the image could not be saved');
        }

        $written = fwrite($handle, $bytes);
        fclose($handle);
        if ($written !== strlen($bytes)) {
            @unlink($dir . $name);
            throw new RuntimeException('the image could not be saved');
        }

        return self::PREFIX . $name;
    }

    /**
     * @return string the file extension for the image type
     * @throws InvalidArgumentException when the data is not a supported image
     */
    public function assertImage(string $bytes): string
    {
        $extension = self::TYPES[(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)] ?? null;
        if ($extension === null || @getimagesizefromstring($bytes) === false) {
            throw new InvalidArgumentException('the file is not a JPEG, PNG, WebP or GIF image');
        }

        return $extension;
    }

    /**
     * Deletes an image that belongs to a recipe. Only files this application manages are removed: uploads,
     * downloaded images named after the recipe's slug, and the default images copied from the seed folder.
     * Other files you placed in the images folder yourself and URLs are never touched.
     */
    public function remove(?string $path, string $ownerSlug = ''): void
    {
        if ($path === null || !$this->isRemovable($path, $ownerSlug)) {
            return;
        }
        $file = $this->publicDir . '/' . $path;
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function isRemovable(string $path, string $ownerSlug): bool
    {
        if (preg_match('#^uploads/[a-f0-9]+\.(?:jpg|png|webp|gif)$#', $path) === 1) {
            return true;
        }

        if (preg_match('#^' . self::DOWNLOAD_DIR . '/([^/]+)\.(?:jpg|png|webp|gif)$#', $path, $m) !== 1) {
            return false;
        }

        // A default image was copied from the seed folder when the installation was set up. It belongs to the
        // recipe that uses it just like a downloaded image, even though its name is not derived from the slug.
        if ($this->defaultsDir !== '' && file_exists($this->defaultsDir . '/images/' . basename($path))) {
            return true;
        }

        return preg_match('/^' . preg_quote($ownerSlug, '/') . '(?:-\d+)?$/', $m[1]) === 1;
    }
}
