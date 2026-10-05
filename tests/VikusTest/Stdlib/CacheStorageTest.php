<?php declare(strict_types=1);

namespace VikusTest\Stdlib;

use PHPUnit\Framework\TestCase;
use Vikus\Stdlib\CacheStorage;

class CacheStorageTest extends TestCase
{
    protected string $base;

    protected CacheStorage $storage;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/vikustest' . bin2hex(random_bytes(4));
        mkdir($this->base, 0775, true);
        $this->storage = new CacheStorage($this->base, 'http://example.org/files');
    }

    protected function tearDown(): void
    {
        $this->storage->deleteAll();
        rmdir($this->base);
    }

    public function testPathAndUrl(): void
    {
        $this->assertSame($this->base . '/vikus/b12', $this->storage->path('b12'));
        $this->assertSame('http://example.org/files/vikus/b12', $this->storage->url('b12'));
    }

    public function testItemSetKey(): void
    {
        $this->assertSame('b12', CacheStorage::blockKey(12));
        $this->assertSame('s2-r7', CacheStorage::itemSetKey(2, 7));
        $this->assertSame($this->base . '/vikus/s2-r7', $this->storage->path('s2-r7'));
    }

    public function testParseKey(): void
    {
        $this->assertSame(['block_id' => 12], CacheStorage::parseKey('b12'));
        $this->assertSame(['site_id' => 2, 'item_set_id' => 7], CacheStorage::parseKey('s2-r7'));
        $this->assertNull(CacheStorage::parseKey('b'));
        $this->assertNull(CacheStorage::parseKey('s2-r'));
        $this->assertNull(CacheStorage::parseKey('../evil'));
    }

    public function testInvalidKeyIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->storage->path('../evil');
    }

    public function testReadMetaIsEmptyWithoutCache(): void
    {
        $this->assertSame([], $this->storage->readMeta('b12'));
    }

    public function testPrepareTempReturnsUniquePaths(): void
    {
        $temp1 = $this->storage->prepareTemp('b12');
        $temp2 = $this->storage->prepareTemp('b12');

        $this->assertNotSame($temp1, $temp2);
        $this->assertMatchesRegularExpression('~/b12\.tmp-[^/]+$~', $temp1);
        $this->assertDirectoryExists($temp1);
        $this->assertDirectoryExists($temp2);

        $this->storage->discardTemp($temp1);
        $this->storage->discardTemp($temp2);
    }

    public function testConcurrentTempDirectoriesDoNotClashOrLeak(): void
    {
        $temp1 = $this->storage->prepareTemp('b12');
        file_put_contents($temp1 . '/meta.json', json_encode(['hash' => 'first']));
        $temp2 = $this->storage->prepareTemp('b12');
        file_put_contents($temp2 . '/meta.json', json_encode(['hash' => 'second']));

        // The second temp directory must not have wiped out the first one.
        $this->assertSame(['hash' => 'first'], json_decode(file_get_contents($temp1 . '/meta.json'), true));

        $this->storage->publish('b12', $temp2);

        $this->assertSame(['hash' => 'second'], $this->storage->readMeta('b12'));
        $this->assertDirectoryExists($temp1);

        $this->storage->discardTemp($temp1);
    }

    public function testDiscardTempRefusesUnrelatedPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->storage->discardTemp($this->base . '/vikus/b12');
    }

    public function testDiscardTempRefusesTraversalOutOfTheCache(): void
    {
        $temp = $this->storage->prepareTemp('b12');
        $outside = $this->base . '/outside.tmp-1';
        mkdir($outside, 0775, true);
        $traversal = dirname($temp) . '/b12.tmp-x/../../outside.tmp-1';
        mkdir(dirname($temp) . '/b12.tmp-x', 0775, true);

        try {
            $this->storage->discardTemp($traversal);
            $this->fail('An escaping path must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertDirectoryExists($outside);
        } finally {
            rmdir($outside);
            $this->storage->discardTemp($temp);
        }
    }

    public function testPublishReplacesPreviousCache(): void
    {
        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', json_encode(['hash' => 'a', 'total' => 1]));
        mkdir($temp . '/sprites');
        file_put_contents($temp . '/sprites/0.jpg', 'x');
        $this->storage->publish('b12', $temp);

        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', json_encode(['hash' => 'b', 'total' => 2]));
        $this->storage->publish('b12', $temp);

        $this->assertSame(['hash' => 'b', 'total' => 2], $this->storage->readMeta('b12'));
        $this->assertFileDoesNotExist($this->storage->path('b12') . '/sprites/0.jpg');
        $this->assertDirectoryDoesNotExist($temp);
        $this->assertSame([], glob($this->storage->path('b12') . '.old*'));
    }

    public function testDeleteReportsAnUnremovableCache(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('The root user ignores the permissions.');
        }
        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', '{}');
        $this->storage->publish('b12', $temp);
        $path = $this->storage->path('b12');
        chmod($path, 0555);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to remove "' . $path . '/meta.json"');
            $this->storage->delete('b12');
        } finally {
            chmod($path, 0775);
        }
    }

    public function testDiscardTempKeepsPreviousCache(): void
    {
        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', json_encode(['hash' => 'a']));
        $this->storage->publish('b12', $temp);

        $temp = $this->storage->prepareTemp('b12');
        $this->storage->discardTemp($temp);

        $this->assertSame(['hash' => 'a'], $this->storage->readMeta('b12'));
    }

    public function testPublishFailureKeepsPreviousCache(): void
    {
        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', json_encode(['hash' => 'a']));
        $this->storage->publish('b12', $temp);

        $missingTemp = $this->storage->path('b12') . '.tmp-missing';
        $this->expectException(\RuntimeException::class);
        try {
            $this->storage->publish('b12', $missingTemp);
        } finally {
            $this->assertSame(['hash' => 'a'], $this->storage->readMeta('b12'));
            $this->assertSame([], glob($this->storage->path('b12') . '.old*'));
        }
    }

    public function testListAndDelete(): void
    {
        foreach (['b3', 's1-r12'] as $key) {
            $temp = $this->storage->prepareTemp($key);
            file_put_contents($temp . '/meta.json', '{}');
            $this->storage->publish($key, $temp);
        }
        $this->assertSame(['b3', 's1-r12'], $this->storage->listKeys());
        $this->storage->delete('b3');
        $this->assertSame(['s1-r12'], $this->storage->listKeys());
    }

    public function testDeleteRemovesLeftoverTempAndOldDirectories(): void
    {
        $temp = $this->storage->prepareTemp('b12');
        file_put_contents($temp . '/meta.json', '{}');
        $this->storage->publish('b12', $temp);
        // Leave a stray temporary directory, as an interrupted job would.
        $leftoverTemp = $this->storage->prepareTemp('b12');
        mkdir($this->storage->path('b12') . '.old', 0775, true);

        $this->storage->delete('b12');

        $this->assertDirectoryDoesNotExist($this->storage->path('b12'));
        $this->assertDirectoryDoesNotExist($leftoverTemp);
        $this->assertDirectoryDoesNotExist($this->storage->path('b12') . '.old');
    }
}
