<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthFactory;
use App\Auth\Csrf;
use App\Auth\Identity;
use App\Auth\LoginThrottle;
use App\Auth\SecurityController;
use App\Auth\WebAuth;
use App\Config\ConfigStore;
use App\Config\JsonFile;
use App\Controller\AdminController;
use App\Controller\AuthController;
use App\Controller\InstallController;
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
use App\Install\Requirements;
use App\Repository\RecipeRepositoryInterface;
use App\Repository\RepositoryFactory;
use App\Service\CurlImageFetcher;
use App\Service\CurlLlmClient;
use App\Service\CurlPageFetcher;
use App\Service\ImageFetcher;
use App\Service\ImageLocalizer;
use App\Service\ImageStorage;
use App\Service\LlmRecipeExtractor;
use App\Service\PageFetcher;
use App\Service\RecipeExtractor;
use App\Service\Seeder;
use App\Validation\RecipeValidator;
use App\View\View;
use Throwable;

final class Application
{
    private readonly Router $router;
    /** @var array<string, mixed> */
    private readonly array $config;
    /** Explicit URL prefix of public/, null when it is detected per request. */
    private readonly ?string $baseUrl;
    /** Contents of the VERSION file at the application root, empty when it cannot be read. */
    private readonly string $version;
    private readonly SecurityController $security;
    private readonly WebAuth $webAuth;
    private readonly Csrf $csrf;
    private readonly ConfigStore $configStore;
    /** A missing config/config.php means this is a fresh installation that still needs setup. */
    private readonly bool $installed;

    /** @param array<string, mixed> $config overrides for defaults/config.php and config/config.php */
    public function __construct(
        private readonly ?RecipeRepositoryInterface $repository = null,
        private readonly bool $debug = false,
        private readonly ?ImageFetcher $fetcher = null,
        array $config = [],
        private readonly ?RecipeExtractor $recipeExtractor = null,
        private readonly ?PageFetcher $pageFetcher = null,
        private readonly ?ImageStorage $images = null,
        ?ConfigStore $configStore = null,
    ) {
        $this->configStore = $configStore ?? new ConfigStore(DEFAULTSDIR . '/config.php', CONFDIR . '/config.php');
        $this->installed = is_file($this->configStore->localFile());

        try {
            // The store knows the shipped defaults and the local differences; caller overrides still win last.
            $config = array_replace($this->configStore->current(), $config);
        } catch (Throwable $e) {
            // Without the shipped defaults a fresh installation cannot be configured, but the setup page
            // must still be able to report the missing files. Once installed, a broken config is an error.
            if ($this->installed) {
                throw $e;
            }
            $config = [];
        }

        $config['base_url'] = ($config['base_url'] ?? null) === null ? null : Request::normalizeBaseUrl((string) $config['base_url']);
        $config['auth'] = AuthFactory::normalize(Config::section($config, 'auth'));
        $config['llm'] = Config::section($config, 'llm');
        $this->config = $config;
        $this->baseUrl = $config['base_url'];
        $this->version = $this->readVersion();

        $this->security = AuthFactory::create($this->config['auth']);
        $this->webAuth = new WebAuth(
            $this->security,
            new LoginThrottle(new JsonFile(STATEDIR . '/login_attempts.json'), (int) $this->config['auth']['lockout_seconds']),
            $this->config['auth'],
        );
        $this->csrf = new Csrf();
        $this->router = $this->installed ? $this->buildRouter() : new Router();
    }

    public function run(?Request $request = null): void
    {
        $this->handle($request ?? Request::fromGlobals($this->baseUrl))->send();
    }

    public function handle(Request $request): Response
    {
        $identity = null;
        if ($this->baseUrl !== null) {
            $request = $request->withBasePath($this->baseUrl);
        }

        if (!$this->installed) {
            return $this->handleInstall($request);
        }

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

    /**
     * A fresh installation answers every request with the setup flow. Static assets never reach the
     * application, and the configuration form only accepts a POST to /install/config.
     */
    private function handleInstall(Request $request): Response
    {
        $installer = new InstallController(BASEDIR . '/templates', $this->configStore, new Requirements());
        $path = $request->path === '/' ? '/' : rtrim($request->path, '/');

        if ($request->method === 'POST' && $path === '/install/config') {
            return $installer->save($request);
        }
        if ($request->method === 'GET' && $path === '/install/config') {
            return $installer->config($request);
        }

        return $installer->check($request);
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

        $html = View::forRequest(BASEDIR . '/templates', $request)
            ->page('error', ['status' => $status, 'message' => $message], 'Error ' . $status . ' – Recipe Box');

        return new Response($html, $status);
    }

    /** @return array<string, mixed> what every template can use to show the right menu and forms */
    private function sharedViewData(Identity $identity, Session $session): array
    {
        $security = $this->security;
        $csrf = $this->csrf;
        $publicDir = BASEDIR . '/public';

        return [
            // Browsers cache scripts and styles without asking the server; a changed file needs a new URL.
            'asset' => static function (string $path) use ($publicDir): string {
                $stamp = is_file($publicDir . $path) ? filemtime($publicDir . $path) : false;

                return $stamp === false ? $path : $path . '?v=' . $stamp;
            },
            'version' => $this->version,
            'authEnabled' => $security->isEnabled(),
            'user' => $identity->accountId !== null && !$identity->isGuest() ? $security->getPublicAccount($identity->accountId) : null,
            'isGuest' => $identity->isGuest(),
            'can' => static fn (string $permission): bool => $security->hasPermission($identity->accountId, $permission),
            'csrf' => static fn (): string => $csrf->token($session),
            'flash' => static fn (): array => $session->pullFlash(),
        ];
    }

    /**
     * The VERSION file at the application root is a single line; the whole text is used. Missing or
     * unreadable files simply yield an empty version.
     */
    private function readVersion(): string
    {
        $file = BASEDIR . '/VERSION';
        $version = is_file($file) ? file_get_contents($file) : false;

        return $version === false ? 'dev' : trim($version);
    }

    private function defaultRepository(): RecipeRepositoryInterface
    {
        $factory = new RepositoryFactory($this->config);

        $fresh = !$factory->exists();
        $repository = $factory->create();
        if ($fresh) {
            (new Seeder())->seed($repository);
        }

        return $repository;
    }

    private function buildRouter(): Router
    {
        $repository = $this->repository ?? $this->defaultRepository();
        $images = $this->images ?? new ImageStorage();
        $localizer = new ImageLocalizer(
            $this->fetcher ?? new CurlImageFetcher(allowPrivateHosts: (bool) ($this->config['import_allow_private_hosts'] ?? false)),
            $images,
        );
        $templates = BASEDIR . '/templates';
        $validator = new RecipeValidator();

        $extractor = $this->recipeExtractor;
        if ($extractor === null) {
            $llm = (array) ($this->config['llm'] ?? []);
            $endpoint = trim((string) ($llm['endpoint'] ?? ''));
            if ($endpoint !== '') {
                $extractor = new LlmRecipeExtractor(
                    new CurlLlmClient(
                        $endpoint,
                        (string) ($llm['api_key'] ?? ''),
                        (string) ($llm['model'] ?? 'gpt-4o-mini'),
                        (int) ($llm['timeout'] ?? 60),
                    ),
                    $this->pageFetcher ?? new CurlPageFetcher(
                        allowPrivateHosts: (bool) ($this->config['import_allow_private_hosts'] ?? false),
                    ),
                    $validator,
                    (string) ($llm['prompt'] ?? ''),
                );
            }
        }

        $recipes = new RecipeController($repository, $validator, $images, $localizer, $extractor);
        $uploads = new UploadController($images);
        $pages = new PageController($repository, $templates, $extractor !== null);
        $auth = new AuthController($this->security, $this->webAuth, $templates, (string) ($this->config['auth']['guest_account'] ?? '') !== '');
        $admin = new AdminController(
            $this->security,
            $this->webAuth,
            $templates,
            $this->configStore,
        );

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
            ->add('GET', '/admin/config', $admin->config(...), '/admin/config')
            ->add('POST', '/admin/config', $admin->configSave(...), 'config_edit')
            ->add('GET', '/api/recipes', $recipes->index(...), 'show')
            ->add('POST', '/api/recipes', $recipes->store(...), 'recipe_create')
            ->add('POST', '/api/recipes/import', $recipes->import(...), 'recipe_import')
            ->add('POST', '/api/recipes/import/url', $recipes->importUrl(...), 'recipe_import')
            ->add('POST', '/api/recipes/import/url/page', $recipes->importPage(...), 'recipe_import')
            ->add('POST', '/api/recipes/import/url/extract', $recipes->importExtract(...), 'recipe_import')
            // The web interface uses POST for everything, because many Apache setups only allow GET and POST.
            // PUT and DELETE remain for API clients that can send them.
            ->add('POST', '/api/recipes/{id}/delete', $recipes->destroy(...), 'recipe_delete')
            ->add('POST', '/api/recipes/{id}', $recipes->update(...), 'recipe_edit')
            ->add('GET', '/api/recipes/export', $recipes->exportAll(...), 'recipe_export_all')
            ->add('GET', '/api/recipes/{id}', $recipes->show(...), 'show')
            ->add('GET', '/api/recipes/{id}/export', $recipes->export(...), 'recipe_export')
            ->add('PUT', '/api/recipes/{id}', $recipes->update(...), 'recipe_edit')
            ->add('DELETE', '/api/recipes/{id}', $recipes->destroy(...), 'recipe_delete')
            ->add('POST', '/api/uploads', $uploads->image(...), 'image_upload');
    }
}
