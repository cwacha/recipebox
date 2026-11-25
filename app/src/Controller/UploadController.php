<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Service\ImageStorage;

final class UploadController extends AbstractController
{
    public function __construct(private readonly ImageStorage $images)
    {
    }

    public function image(Request $request): Response
    {
        $file = $request->files['image'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(400, 'No image was uploaded.');
        }

        return $this->json(['path' => $this->images->store($file)], 201);
    }
}
