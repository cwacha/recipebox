<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\HttpException;

/** Stores uploaded recipe images below public/uploads. */
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

    public function __construct(private readonly string $publicDir)
    {
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

    /** Deletes an image previously stored by this class. Other paths and URLs are ignored. */
    public function remove(?string $path): void
    {
        if ($path === null || preg_match('#^uploads/[a-f0-9]+\.(?:jpg|png|webp|gif)$#', $path) !== 1) {
            return;
        }
        $file = $this->publicDir . '/' . $path;
        if (is_file($file)) {
            unlink($file);
        }
    }
}
