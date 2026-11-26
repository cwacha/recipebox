<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Http\Response;

abstract class AbstractController
{
    /** The configured site title, "Recipe Box" when it is empty (e.g. on the setup pages). */
    protected function appTitle(Request $request): string
    {
        $title = trim((string) $request->attribute('appTitle', ''));

        return $title === '' ? 'Recipe Box' : $title;
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    protected function download(mixed $data, string $filename): JsonResponse
    {
        return new class ($data, $filename) extends JsonResponse {
            public function __construct(mixed $data, string $filename)
            {
                parent::__construct($data, 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }
        };
    }

    protected function redirect(string $location, int $status = 301): RedirectResponse
    {
        return new RedirectResponse($location, $status);
    }

    protected function html(string $html, int $status = 200): Response
    {
        return new Response($html, $status);
    }
}
