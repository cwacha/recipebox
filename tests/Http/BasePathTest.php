<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class BasePathTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testNormalisesBaseUrls(): void
    {
        self::assertSame('', Request::normalizeBaseUrl(''));
        self::assertSame('', Request::normalizeBaseUrl('/'));
        self::assertSame('/~user/recipebox/public', Request::normalizeBaseUrl('/~user/recipebox/public/'));
        self::assertSame('/~user/recipebox/public', Request::normalizeBaseUrl('http://localhost/~user/recipebox/public/?x=1'));
        self::assertSame('/double/slash', Request::normalizeBaseUrl('//double//slash/'));
        self::assertSame('', Request::normalizeBaseUrl('/with/../up'), 'traversal is refused');
        self::assertSame('', Request::normalizeBaseUrl('/back\\slash'));
    }

    /** @param array<string, string> $server */
    private function request(string $url, array $server, ?string $baseUrl = null): Request
    {
        $_SERVER = $server + ['REQUEST_URI' => $url, 'REQUEST_METHOD' => 'GET'];

        return Request::fromGlobals($baseUrl);
    }

    public function testDetectsAPrefixFromTheFrontController(): void
    {
        $server = ['SCRIPT_NAME' => '/~user/recipebox/public/index.php'];

        $home = $this->request('/~user/recipebox/public/', $server);
        self::assertSame('/~user/recipebox/public', $home->basePath);
        self::assertSame('/', $home->path);

        $recipe = $this->request('/~user/recipebox/public/recipes/spaghetti-carbonara', $server);
        self::assertSame('/recipes/spaghetti-carbonara', $recipe->path);
        self::assertSame('/~user/recipebox/public', $recipe->basePath);

        $noSlash = $this->request('/~user/recipebox/public', $server);
        self::assertSame('/', $noSlash->path);

        $query = $this->request('/~user/recipebox/public/?q=tomato', $server);
        self::assertSame('/', $query->path);
    }

    public function testDocumentRootHasAnEmptyPrefix(): void
    {
        $root = $this->request('/recipes/spaghetti-carbonara', ['SCRIPT_NAME' => '/index.php']);
        self::assertSame('', $root->basePath);
        self::assertSame('/recipes/spaghetti-carbonara', $root->path);

        $dir = $this->request('/recipes/public/recipes/x', ['SCRIPT_NAME' => '/recipes/public/index.php']);
        self::assertSame('/recipes/public', $dir->basePath);
        self::assertSame('/recipes/x', $dir->path);
    }

    public function testAnExplicitBaseWinsAndAMismatchFallsBackToDetection(): void
    {
        $server = ['SCRIPT_NAME' => '/app/public/index.php'];

        $fits = $this->request('/app/public/recipes/x', $server, '/app/public/');
        self::assertSame('/app/public', $fits->basePath);
        self::assertSame('/recipes/x', $fits->path);

        // behind a proxy that strips the prefix the request path is already relative: nothing to strip
        $stripped = $this->request('/recipes/x', $server, '/app/public');
        self::assertSame('/app/public', $stripped->basePath, 'configured prefix is kept for links');
        self::assertSame('/recipes/x', $stripped->path, 'the path stays as the proxy delivered it');

        $wrong = $this->request('/elsewhere/recipes/x', $server, '/does/not/fit');
        self::assertSame('/does/not/fit', $wrong->basePath, 'an explicit setting is authoritative');
        self::assertSame('/elsewhere/recipes/x', $wrong->path, 'and the path is taken as delivered');

        $detected = $this->request('/app/public/recipes/x', $server);
        self::assertSame('/app/public', $detected->basePath, 'without a setting the script location decides');
    }

    public function testWithBasePathKeepsTheRestOfTheRequest(): void
    {
        $request = new Request('POST', '/admin/account/edit', '', ['a' => '1'], '{"x":1}', [], ['_csrf' => 't'], ['c' => 'v'], ['x-csrf-token' => 't'], ['REMOTE_ADDR' => '1.2.3.4']);

        $copy = $request->withBasePath('/~user/recipebox/public');

        self::assertSame('/~user/recipebox/public', $copy->basePath);
        self::assertSame($request->path, $copy->path);
        self::assertSame($request->post, $copy->post);
        self::assertSame($request->rawBody, $copy->rawBody);
        self::assertSame($request->headers, $copy->headers);
    }
}
