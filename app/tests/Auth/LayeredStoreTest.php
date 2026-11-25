<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Exception\StorageFailed;
use App\Auth\JsonFile;
use App\Auth\LayeredStore;

final class LayeredStoreTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaults('things', ['a' => 'default-a', 'b' => 'default-b', 'c' => 'default-c']);
    }

    public function testLocalChangesAreLayeredOverTheDefaults(): void
    {
        file_put_contents($this->dir . '/state/things.json', json_encode(['a' => 'local-a', 'b' => null, 'd' => 'local-d']));

        $store = $this->store('things');
        $store->load();

        self::assertSame(['a' => 'local-a', 'c' => 'default-c', 'd' => 'local-d'], $store->all());
        self::assertTrue($store->isDefault('a'));
        self::assertFalse($store->isDefault('d'));
    }

    public function testOnlyDifferencesToTheDefaultsAreWrittenAndDefaultsStayUntouched(): void
    {
        $defaultsBefore = (string) file_get_contents($this->dir . '/defaults/things.json');
        $store = $this->store('things');
        $store->load();

        $store->set('a', 'changed');          // differs from the default
        $store->set('c', 'default-c');        // same as the default: nothing to store
        $store->set('e', 'new');              // added
        $store->remove('b');                  // removing a default record leaves a marker
        $store->save();

        self::assertSame(['a' => 'changed', 'e' => 'new', 'b' => null], $this->localFile('things'));
        self::assertSame($defaultsBefore, file_get_contents($this->dir . '/defaults/things.json'));

        $reloaded = $this->store('things');
        $reloaded->load();
        self::assertSame(['a' => 'changed', 'c' => 'default-c', 'e' => 'new'], $reloaded->all());
    }

    public function testChangingBackToTheDefaultOrRemovingALocalRecordDropsTheOverride(): void
    {
        file_put_contents($this->dir . '/state/things.json', json_encode(['a' => 'local-a', 'b' => null, 'd' => 'local-d']));
        $store = $this->store('things');
        $store->load();

        $store->set('a', 'default-a');
        $store->set('b', 'default-b');
        $store->remove('d');
        $store->save();

        self::assertSame([], $this->localFile('things'));
    }

    public function testUnrelatedAndConcurrentLocalEntriesAreKept(): void
    {
        file_put_contents($this->dir . '/state/things.json', json_encode(['unrelated' => ['keep' => true]]));
        $first = $this->store('things');
        $second = $this->store('things');
        $first->load();
        $second->load();

        $first->set('x', 'from first');
        $second->set('y', 'from second');
        $first->save();
        $second->save();

        self::assertSame(['unrelated' => ['keep' => true], 'x' => 'from first', 'y' => 'from second'], $this->localFile('things'));
    }

    public function testFailuresAreReportedInsteadOfIgnored(): void
    {
        file_put_contents($this->dir . '/state/broken.json', '{not json');
        $broken = new LayeredStore(new JsonFile($this->dir . '/defaults/things.json'), new JsonFile($this->dir . '/state/broken.json'));
        try {
            $broken->load();
            self::fail('invalid JSON must not be treated as empty configuration');
        } catch (StorageFailed $e) {
            self::assertStringContainsString('Invalid JSON', $e->getMessage());
        }

        file_put_contents($this->dir . '/blocker', 'a file where a directory is needed');
        $unwritable = new LayeredStore(new JsonFile($this->dir . '/defaults/things.json'), new JsonFile($this->dir . '/blocker/things.json'));
        $unwritable->load();
        $unwritable->set('a', 'x');
        $this->expectException(StorageFailed::class);
        $unwritable->save();
    }

    public function testLocalFilesAreNotReadableByOthers(): void
    {
        $store = $this->store('things');
        $store->load();
        $store->set('a', 'x');
        $store->save();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/state/things.json')), -4));
    }
}
