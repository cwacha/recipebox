<?php

declare(strict_types=1);

namespace Tests;

use App\Config\ConfigStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigStoreTest extends TestCase
{
    private string $dir;
    private string $defaultsFile;
    private string $localFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-config-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0775, true);
        $this->defaultsFile = $this->dir . '/config/defaults.php';
        $this->localFile = $this->dir . '/config/config.php';

        $defaults = [
            'name' => 'default',
            'enabled' => true,
            'count' => 5,
            'mode' => 'a',
            'nullable' => null,
        ];
        file_put_contents($this->defaultsFile, "<?php\n\nreturn " . var_export($defaults, true) . ";\n");
    }

    protected function tearDown(): void
    {
        foreach (['/config/config.php', '/config/defaults.php'] as $file) {
            if (is_file($this->dir . $file)) {
                unlink($this->dir . $file);
            }
        }
        foreach (glob($this->dir . '/config/config.php.tmp.*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testOnlyDifferencesAreWritten(): void
    {
        $store = new ConfigStore($this->defaultsFile, $this->localFile);

        $store->save([
            'name' => 'default',
            'enabled' => false,
            'count' => 5,
            'mode' => 'b',
            'nullable' => null,
        ]);

        self::assertSame(['enabled' => false, 'mode' => 'b'], $store->local());
        self::assertSame([
            'name' => 'default',
            'enabled' => false,
            'count' => 5,
            'mode' => 'b',
            'nullable' => null,
        ], $store->current());

        $contents = (string) file_get_contents($this->localFile);
        self::assertStringContainsString('managed by the application', $contents);
        self::assertStringContainsString('only the settings that differ', $contents);
        self::assertStringContainsString("'enabled' => false", $contents);
        self::assertStringContainsString("'mode' => 'b'", $contents);
        self::assertStringNotContainsString("'name'", $contents, 'a value equal to the default is not stored');
        self::assertStringNotContainsString("'count'", $contents);
    }

    public function testResettingAValueToItsDefaultRemovesItAgain(): void
    {
        $store = new ConfigStore($this->defaultsFile, $this->localFile);
        $store->save(['enabled' => false, 'mode' => 'b'] + $store->defaults());
        self::assertNotSame([], $store->local());

        $store->save($store->defaults());

        self::assertSame([], $store->local());
        self::assertStringContainsString("return [\n];", (string) file_get_contents($this->localFile));
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $store = new ConfigStore($this->defaultsFile, $this->localFile);
        $store->save(['unknown' => 'injected'] + $store->defaults());

        self::assertSame([], $store->local());
        self::assertStringNotContainsString('unknown', (string) file_get_contents($this->localFile));
    }

    public function testMissingLocalFileMeansDefaultsAndAnInvalidFileIsRejected(): void
    {
        $store = new ConfigStore($this->defaultsFile, $this->localFile);
        self::assertSame([], $store->local());
        self::assertSame($store->defaults(), $store->current());

        file_put_contents($this->localFile, "<?php\nreturn 'not an array';\n");
        $this->expectException(RuntimeException::class);
        $store->local();
    }
}
