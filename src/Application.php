<?php

declare(strict_types=1);

namespace App;

use App\Controller\PageController;
use App\Controller\RecipeController;
use App\Controller\UploadController;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\RecipeRepositoryInterface;
use App\Repository\RepositoryFactory;
use App\Service\ImageStorage;
use App\Service\Seeder;
use App\Validation\RecipeValidator;
use App\View\View;
use Throwable;

final class Application
{
    private readonly Router $router;

    public function __construct(
        private readonly string $rootDir,
        private readonly ?RecipeRepositoryInterface $repository = null,
        private readonly bool $debug = false,
    ) {
        $this->router = $this->buildRouter();
    }

    public function run(?Request $request = null): void
    {
        $this->handle($request ?? Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (HttpException $e) {
            return $this->error($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            error_log((string) $e);

            return $this->error($request, 500, $this->debug ? $e->getMessage() : 'Internal server error');
        }
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if (str_starts_with($request->path, '/api/')) {
            return new JsonResponse(['error' => $message], $status);
        }

        $html = (new View($this->rootDir . '/templates', $request->basePath))
            ->page('error', ['status' => $status, 'message' => $message], 'Error ' . $status . ' – Recipe Box');

        return new Response($html, $status);
    }

    private function defaultRepository(): RecipeRepositoryInterface
    {
        $factory = new RepositoryFactory(require $this->rootDir . '/config/app.php');

        $fresh = !$factory->exists();
        $repository = $factory->create();
        if ($fresh) {
            (new Seeder($this->rootDir . '/defaults', $this->rootDir . '/public'))->seed($repository);
        }

        return $repository;
    }

    private function buildRouter(): Router
    {
        $repository = $this->repository ?? $this->defaultRepository();
        $images = new ImageStorage($this->rootDir . '/public');

        $recipes = new RecipeController($repository, new RecipeValidator(), $images);
        $uploads = new UploadController($images);
        $pages = new PageController($repository, $this->rootDir . '/templates');

        return (new Router())
            ->add('GET', '/', $pages->home(...))
            ->add('GET', '/recipes/new', $pages->create(...))
            ->add('GET', '/recipes/{id}', $pages->show(...))
            ->add('GET', '/recipes/{id}/edit', $pages->edit(...))
            ->add('GET', '/import', $pages->import(...))
            ->add('GET', '/api/recipes', $recipes->index(...))
            ->add('POST', '/api/recipes', $recipes->store(...))
            ->add('POST', '/api/recipes/import', $recipes->import(...))
            ->add('GET', '/api/recipes/export', $recipes->exportAll(...))
            ->add('GET', '/api/recipes/{id}', $recipes->show(...))
            ->add('GET', '/api/recipes/{id}/export', $recipes->export(...))
            ->add('PUT', '/api/recipes/{id}', $recipes->update(...))
            ->add('DELETE', '/api/recipes/{id}', $recipes->destroy(...))
            ->add('POST', '/api/uploads', $uploads->image(...));
    }
}
