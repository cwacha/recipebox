<?php

declare(strict_types=1);

namespace Tests;

use App\Service\CurlImageFetcher;
use App\Service\ImageFetchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlImageFetcherTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testClassifiesAddresses(string $ip, bool $public): void
    {
        self::assertSame($public, CurlImageFetcher::isPublicAddress($ip));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['93.184.216.34', true];
        yield 'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'private 10/8' => ['10.1.2.3', false];
        yield 'private 172.16/12' => ['172.20.0.1', false];
        yield 'private 192.168/16' => ['192.168.1.10', false];
        yield 'cloud metadata' => ['169.254.169.254', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'carrier grade nat' => ['100.64.0.1', false];
        yield 'v6 loopback' => ['::1', false];
        yield 'v6 link local' => ['fe80::1', false];
        yield 'v6 unique local' => ['fd00::1', false];
        yield 'v4 mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'v4 mapped private' => ['::ffff:192.168.0.1', false];
        yield 'nonsense' => ['not-an-ip', false];
    }

    #[DataProvider('blockedUrls')]
    public function testRefusesDangerousUrlsWithoutConnecting(string $url, string $reason): void
    {
        $this->expectException(ImageFetchException::class);
        $this->expectExceptionMessageMatches($reason);

        (new CurlImageFetcher())->fetch($url);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback ip' => ['http://127.0.0.1/a.jpg', '/public internet/'];
        yield 'localhost name' => ['http://localhost/a.jpg', '/public internet/'];
        yield 'metadata service' => ['http://169.254.169.254/latest/meta-data/', '/public internet/'];
        yield 'private network' => ['https://192.168.1.1/a.jpg', '/public internet/'];
        yield 'v6 loopback' => ['http://[::1]/a.jpg', '/public internet/'];
        yield 'decimal ip trick' => ['http://2130706433/a.jpg', '/public internet|not found/'];
        yield 'file scheme' => ['file:///etc/passwd', '/only http/'];
        yield 'ftp scheme' => ['ftp://example.com/a.jpg', '/only http/'];
        yield 'odd port' => ['http://93.184.216.34:22/a.jpg', '/port 22/'];
        yield 'credentials' => ['http://user:pw@93.184.216.34/a.jpg', '/credentials/'];
        yield 'no host' => ['http:///a.jpg', '/only http/'];
    }
}
