<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthFactory;
use App\Auth\Csrf;
use App\Auth\Identity;
use App\Auth\JsonFile;
use App\Auth\LoginThrottle;
use App\Auth\SecurityController;
use App\Auth\WebAuth;
use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\PageController;
use App\Controller\RecipeController;
use App\Controller\UploadController;
use App\Exception\HttpException;
use App\Exception\ValidationException;
use App\Http\JsonResponse;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Repository\RecipeRepositoryInterface;
use App\Repository\RepositoryFactory;
use App\Service\CurlImageFetcher;
use App\Service\ImageFetcher;
use App\Service\ImageLocalizer;
use App\Service\ImageStorage;
use App\Service\Seeder;
use App\Validation\RecipeValidator;
use App\View\View;
use Throwable;

final class Application
{
    private readonly Router $router;
    /** @var array<string, mixed> */
    private readonly array $config;
    private readonly SecurityController $security;
    private readonly WebAuth $webAuth;
    private readonly Csrf $csrf;

    /** @param array<string, mixed> $config overrides for config/app.php */
    public function __construct(
        private readonly string $rootDir,
        private readonly ?RecipeRepositoryInterface $repository = null,
        private readonly bool $debug = false,
        private readonly ?ImageFetcher $fetcher = null,
        array $config = [],
    ) {
        $config = array_replace_recursive(require $rootDir . '/config/app.php', $config);
        $config['auth'] = AuthFactory::normalize((array) ($config['auth'] ?? []), $rootDir);
        $this->config = $config;

        $this->security = AuthFactory::create($this->config['auth']);
        $this->webAuth = new WebAuth(
            $this->security,
            new LoginThrottle(new JsonFile($this->config['auth']['state_dir'] . '/login_attempts.json'), (int) $this->config['auth']['lockout_seconds']),
            $this->config['auth'],
        );
        $this->csrf = new Csrf();
        $this->router = $this->buildRouter();
    }

    public function run(?Request $request = null): void
    {
        $this->handle($request ?? Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        $identity = null;

        try {
            $session = $request->attribute('session') ?? Session::native($this->webAuth->sessionOptions($request), $request->cookies);
            $identity = $this->webAuth->identify($request, $session);
            $request = $request
                ->with('session', $session)
                ->with('identity', $identity)
                ->with('shared', $this->sharedViewData($identity, $session));

            $response = $identity->failed()
                ? $this->unauthorized($request, 'Invalid credentials.')
                : $this->dispatch($request, $identity, $session);
        } catch (ValidationException $e) {
            $response = new JsonResponse(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            error_log((string) $e);

            $response = $this->error($request, 500, $this->debug ? $e->getMessage() : 'Internal server error');
        }

        // e.g. a renewed persistent-login cookie must reach the browser whatever the outcome of the request was
        foreach ($identity?->cookies ?? [] as $cookie) {
            $response->addCookie($cookie);
        }

        return $response;
    }

    private function dispatch(Request $request, Identity $identity, Session $session): Response
    {
        $route = $this->router->match($request);

        if ($route->permission !== null && !$this->security->hasPermission($identity->accountId, $route->permission)) {
            return $this->deny($request, $identity);
        }

        // cookies are sent automatically by the browser, so changes made with them need proof that the page is ours
        if ($this->security->isEnabled() && $request->isUnsafe() && $identity->via !== Identity::BASIC
            && !$this->csrf->isValid($session, $request->header('x-csrf-token') ?? ($request->post['_csrf'] ?? null))
        ) {
            throw new HttpException(403, 'The page has expired. Please reload it and try again.');
        }

        return $route->run($request);
    }

    /**
     * Not signed in: ask for credentials. Signed in without the permission: say so and fall back to the
     * implicit "show" action, the recipe list.
     */
    private function deny(Request $request, Identity $identity): Response
    {
        $signedIn = $identity->isUser();

        if (str_starts_with($request->path, '/api/')) {
            return $signedIn
                ? new JsonResponse(['error' => 'You do not have permission to do this.'], 403)
                : $this->unauthorized($request, 'Please sign in.');
        }

        $session = $request->session();
        if (!$signedIn) {
            $next = $request->method === 'GET' ? $request->path . ($request->query === [] ? '' : '?' . http_build_query($request->query)) : '/';
            $session->flash('warning', 'Please sign in to continue.');

            return new RedirectResponse($request->basePath . '/login?next=' . rawurlencode($next), 302);
        }

        if ($request->path === '/') {
            return $this->error($request, 403, 'Your account is not allowed to see recipes.');
        }
        $session->flash('danger', 'You do not have permission to open that page.');

        return new RedirectResponse($request->basePath . '/', 302);
    }

    private function unauthorized(Request $request, string $message): Response
    {
        // scripts of our own pages handle a 401 themselves; the header would make browsers open a password dialog for them
        $headers = $request->header('x-requested-with') === null ? ['WWW-Authenticate' => 'Basic realm="Recipe Box", charset="UTF-8"'] : [];

        if (str_starts_with($request->path, '/api/')) {
            return new JsonResponse(['error' => $message], 401, $headers);
        }

        $response = $this->error($request, 401, $message);

        return new Response($response->body(), 401, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if (str_starts_with($request->path, '/api/')) {
            return new JsonResponse(['error' => $message], $status);
        }

        $html = View::forRequest($this->rootDir . '/templates', $request)
            ->page('error', ['status' => $status, 'message' => $message], 'Error ' . $status . ' – Recipe Box');

        return new Response($html, $status);
    }

    /** @return array<string, mixed> what every template can use to show the right menu and forms */
    private function sharedViewData(Identity $identity, Session $session): array
    {
        $security = $this->security;
        $csrf = $this->csrf;

        return [
            'authEnabled' => $security->isEnabled(),
            'user' => $identity->accountId !== null && !$identity->isGuest() ? $security->getPublicAccount($identity->accountId) : null,
            'isGuest' => $identity->isGuest(),
            'can' => static fn (string $permission): bool => $security->hasPermission($identity->accountId, $permission),
            'csrf' => static fn (): string => $csrf->token($session),
            'flash' => static fn (): array => $session->pullFlash(),
        ];
    }

    private function defaultRepository(): RecipeRepositoryInterface
    {
        $factory = new RepositoryFactory($this->config);

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
        $images = new ImageStorage($this->rootDir . '/public', $this->rootDir . '/defaults');
        $localizer = new ImageLocalizer(
            $this->fetcher ?? new CurlImageFetcher(allowPrivateHosts: (bool) ($this->config['import_allow_private_hosts'] ?? false)),
            $images,
            $this->rootDir . '/public',
        );
        $templates = $this->rootDir . '/templates';

        $recipes = new RecipeController($repository, new RecipeValidator(), $images, $localizer);
        $uploads = new UploadController($images);
        $pages = new PageController($repository, $templates);
        $auth = new AuthController($this->security, $this->webAuth, $templates, (string) ($this->config['auth']['guest_account'] ?? '') !== '');
        $admin = new AdminController($this->security, $this->webAuth, $templates);

        // The last argument is what the account needs for the route: a view (starts with "/") or an action.
        // null means the route is public. "show" is something every identified account may do.
        return (new Router())
            ->add('GET', '/login', $auth->loginForm(...), null)
            ->add('POST', '/login', $auth->login(...), null)
            ->add('POST', '/logout', $auth->logout(...), null)
            ->add('GET', '/account/password', $auth->passwordForm(...), '/account/password')
            ->add('POST', '/account/password', $auth->passwordSave(...), '/account/password')
            ->add('GET', '/', $pages->home(...), '/')
            ->add('GET', '/recipes/new', $pages->create(...), '/recipes/new')
            ->add('GET', '/recipes/{slug}', $pages->show(...), '/recipes')
            ->add('GET', '/recipes/{slug}/edit', $pages->edit(...), '/recipes/edit')
            ->add('GET', '/import', $pages->import(...), '/import')
            ->add('GET', '/admin', $admin->index(...), '/admin')
            ->add('GET', '/admin/accounts', $admin->accounts(...), '/admin/accounts')
            ->add('GET', '/admin/account', $admin->account(...), '/admin/account')
            ->add('GET', '/admin/account/edit', $admin->accountEdit(...), '/admin/account/edit')
            ->add('POST', '/admin/account/edit', $admin->accountSave(...), 'account_edit')
            ->add('GET', '/admin/account/password', $admin->accountPassword(...), '/admin/account/password')
            ->add('POST', '/admin/account/password', $admin->accountPasswordSave(...), 'account_password')
            ->add('POST', '/admin/account/delete', $admin->accountDelete(...), 'account_delete')
            ->add('GET', '/admin/roles', $admin->roles(...), '/admin/roles')
            ->add('GET', '/admin/role/edit', $admin->roleEdit(...), '/admin/role/edit')
            ->add('POST', '/admin/role/edit', $admin->roleSave(...), 'role_edit')
            ->add('POST', '/admin/role/delete', $admin->roleDelete(...), 'role_delete')
            ->add('GET', '/api/recipes', $recipes->index(...), 'show')
            ->add('POST', '/api/recipes', $recipes->store(...), 'recipe_create')
            ->add('POST', '/api/recipes/import', $recipes->import(...), 'recipe_import')
            ->add('GET', '/api/recipes/export', $recipes->exportAll(...), 'show')
            ->add('GET', '/api/recipes/{id}', $recipes->show(...), 'show')
            ->add('GET', '/api/recipes/{id}/export', $recipes->export(...), 'show')
            ->add('PUT', '/api/recipes/{id}', $recipes->update(...), 'recipe_edit')
            ->add('DELETE', '/api/recipes/{id}', $recipes->destroy(...), 'recipe_delete')
            ->add('POST', '/api/uploads', $uploads->image(...), 'image_upload');
    }
}
