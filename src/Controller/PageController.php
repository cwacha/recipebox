<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class PageController extends AbstractController
{
    public function __construct(private readonly string $templateDir)
    {
    }

    public function home(Request $request): Response
    {
        $basePath = $request->basePath;
        $servings = \App\Model\Recipe::SERVINGS;

        ob_start();
        require $this->templateDir . '/home.php';

        return $this->html((string) ob_get_clean());
    }
}
