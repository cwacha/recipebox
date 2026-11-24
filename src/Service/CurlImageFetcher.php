<?php

declare(strict_types=1);

namespace App\Service;

use CurlHandle;

/**
 * Downloads images from the internet on behalf of anonymous users, so it must not become a way to reach
 * internal services (SSRF). Therefore: only http(s) on the standard ports, no credentials in the URL, the
 * host must resolve to public addresses only (checked again for every redirect), the checked address is pinned
 * for the connection (no DNS rebinding), redirects are followed manually, and size and time are limited.
 */
final class CurlImageFetcher implements ImageFetcher
{
    private const ALLOWED_PORTS = [80, 443];
    private const REDIRECT_STATUS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly int $maxBytes = 8 * 1024 * 1024,
        private readonly int $timeout = 15,
        private readonly int $maxRedirects = 3,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    public function fetch(string $url): string
    {
        if (!extension_loaded('curl')) {
            throw new ImageFetchException('the curl PHP extension is not installed');
        }

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            [$status, $location, $body] = $this->request($url);

            if (in_array($status, self::REDIRECT_STATUS, true) && $location !== null) {
                $url = $this->absolute($url, $location);
                continue;
            }
            if ($status !== 200) {
                throw new ImageFetchException("the server answered with HTTP $status");
            }

            return $body;
        }

        throw new ImageFetchException('too many redirects');
    }

    /** Whether an IP address belongs to the public internet (not private, loopback, link-local, reserved, ...). */
    public static function isPublicAddress(string $ip): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $ipv4Mapped = str_repeat("\0", 10) . "\xff\xff";
            $nat64 = "\x00\x64\xff\x9b" . str_repeat("\0", 8);
            if (str_starts_with($packed, $ipv4Mapped) || str_starts_with($packed, $nat64)) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }
        } elseif (ord($packed[0]) === 100 && (ord($packed[1]) & 0xC0) === 64) {
            return false; // 100.64.0.0/10 carrier-grade NAT
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return array{int, ?string, string} status, Location header, body */
    private function request(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ImageFetchException('only http and https URLs are supported');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageFetchException('URLs with credentials are not supported');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new ImageFetchException("port $port is not allowed");
        }

        $ip = $this->resolve($host);

        $location = null;
        $body = '';
        $tooLarge = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'RecipeBox/1.0 (image import)',
            CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $ch, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;

                    return -1;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($tooLarge) {
            throw new ImageFetchException(sprintf('the file is larger than %d MB', intdiv($this->maxBytes, 1048576)));
        }
        if ($ok === false) {
            throw new ImageFetchException($error !== '' ? $error : 'the request failed');
        }

        return [$status, $location, $body];
    }

    /** Resolves the host and returns the address to connect to; fails if any address is not public. */
    private function resolve(string $host): string
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
            $ips = array_values(array_filter($ips));
            if ($ips === []) {
                $ips = @gethostbynamel($host) ?: [];
            }
        }
        if ($ips === []) {
            throw new ImageFetchException("host \"$host\" was not found");
        }

        if (!$this->allowPrivateHosts) {
            foreach ($ips as $ip) {
                if (!self::isPublicAddress($ip)) {
                    throw new ImageFetchException('the address is not on the public internet');
                }
            }
        }

        foreach ($ips as $ip) {
            if (!str_contains($ip, ':')) {
                return $ip;
            }
        }

        return $ips[0];
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $location;
    }
}
