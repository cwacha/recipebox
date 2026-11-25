<?php

declare(strict_types=1);

namespace Tests;

use App\Service\CurlPageFetcher;
use App\Service\RecipeImportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlPageFetcherTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function testRefusesDangerousUrlsWithoutConnecting(string $url, string $reason): void
    {
        $this->expectException(RecipeImportException::class);
        $this->expectExceptionMessageMatches($reason);

        (new CurlPageFetcher())->fetch($url);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback ip' => ['http://127.0.0.1/recipe', '/public internet/'];
        yield 'localhost name' => ['http://localhost/recipe', '/public internet/'];
        yield 'metadata service' => ['http://169.254.169.254/latest/meta-data/', '/public internet/'];
        yield 'private network' => ['https://192.168.1.1/recipe', '/public internet/'];
        yield 'v6 loopback' => ['http://[::1]/recipe', '/public internet/'];
        yield 'decimal ip trick' => ['http://2130706433/recipe', '/public internet|not found/'];
        yield 'file scheme' => ['file:///etc/passwd', '/only http/'];
        yield 'ftp scheme' => ['ftp://example.com/recipe', '/only http/'];
        yield 'odd port' => ['http://93.184.216.34:22/recipe', '/port 22/'];
        yield 'credentials' => ['http://user:pw@93.184.216.34/recipe', '/credentials/'];
        yield 'no host' => ['http:///recipe', '/only http/'];
    }
}
