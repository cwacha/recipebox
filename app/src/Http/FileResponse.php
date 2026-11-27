<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Sends a file from the temporary directory as a download and removes it afterwards. The body is read lazily,
 * so a large export does not have to be held in memory as a PHP string.
 */
final class FileResponse extends Response
{
    public function __construct(
        private readonly string $file,
        string $filename,
        string $contentType = 'application/octet-stream',
    ) {
        $size = is_file($file) ? filesize($file) : false;

        parent::__construct('', 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Content-Length' => (string) ($size === false ? 0 : $size),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function send(): void
    {
        $this->sendHeaders();
        readfile($this->file);
        @unlink($this->file);
    }

    /** Reads the file for tests; removes it like send() does. */
    public function body(): string
    {
        $body = (string) @file_get_contents($this->file);
        @unlink($this->file);

        return $body;
    }

    /** A response that is never sent (for example due to an error) must not leave its temporary file behind. */
    public function __destruct()
    {
        @unlink($this->file);
    }
}
