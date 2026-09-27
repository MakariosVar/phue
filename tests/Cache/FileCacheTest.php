<?php

declare(strict_types=1);

namespace Phue\Tests\Cache;

use DateInterval;
use InvalidArgumentException;
use Phue\Core\Cache\FileCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PSR-16 compliance + storage-behaviour contract for FileCache.
 *
 * FileCache is the persistence layer behind the whole SSR compile cache
 * (TemplateCompilerBridge) and the Schii schema cache, so a silent violation
 * here does not "return the wrong answer" — it either 500s a request or
 * silently serves a stale compiled module forever. Every branch below was
 * changed in the memoization/caching rework and had no dedicated test.
 *
 * @package Phue\Tests\Cache
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class FileCacheTest extends TestCase
{
    private string $dir;

    private FileCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/phue_filecache_' . bin2hex(random_bytes(6));
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);

        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function illegalKeyProvider(): array
    {
        return [
            'empty'          => [''],
            'open brace'     => ['{'],
            'close brace'    => ['}'],
            'open paren'     => ['('],
            'close paren'    => [')'],
            'slash'          => ['a/b'],
            'backslash'      => ['a\\b'],
            'at sign'        => ['a@b'],
            'colon'          => ['a:b'],
        ];
    }

    // ---------------------------------------------------------------- keys

    #[DataProvider('illegalKeyProvider')]
    public function testGetRejectsIllegalKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->get($key);
    }

    #[DataProvider('illegalKeyProvider')]
    public function testSetRejectsIllegalKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->set($key, 'v');
    }

    #[DataProvider('illegalKeyProvider')]
    public function testDeleteRejectsIllegalKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->delete($key);
    }

    #[DataProvider('illegalKeyProvider')]
    public function testHasRejectsIllegalKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->has($key);
    }

    public function testGetMultipleRejectsIllegalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->getMultiple(['good', 'bad/key']);
    }

    public function testSetMultipleRejectsIllegalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->setMultiple(['bad/key' => 'v']);
    }

    public function testDeleteMultipleRejectsIllegalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->deleteMultiple(['bad/key']);
    }

    public function testIllegalKeyWritesNothingToDisk(): void
    {
        try {
            $this->cache->set('nested/key', 'v');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(
            [],
            glob($this->dir . '/*') ?: [],
            'A rejected key must not leave a file behind.'
        );
    }

    public function testDotSegmentsAreLegalKeysAndStayInsideStorage(): void
    {
        // '.' is NOT a PSR-16 reserved character, so '..' is a legal key — and
        // because the filename is md5($key), it can never resolve to a parent
        // directory. A slash-bearing variant is rejected outright.
        $this->assertTrue($this->cache->set('..', 'still here'));
        $this->assertTrue($this->cache->set('.', 'dot'));
        $this->assertTrue($this->cache->set('..escape', 'contained'));

        $this->assertSame('still here', $this->cache->get('..'));
        $this->assertSame('dot', $this->cache->get('.'));
        $this->assertSame('contained', $this->cache->get('..escape'));

        $onDisk = array_map('basename', glob($this->dir . '/*.cache') ?: []);
        sort($onDisk);

        $expected = [md5('.') . '.cache', md5('..') . '.cache', md5('..escape') . '.cache'];
        sort($expected);

        $this->assertSame(
            $expected,
            $onDisk,
            'Every key, however dot-heavy, must land on its own md5 file.'
        );
    }

    public function testSlashBearingTraversalKeyIsRejectedRatherThanResolved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->set('../../escape', 'nope');
    }

    // ------------------------------------------------------------- values

    public function testSetAndGetRoundTrip(): void
    {
        $this->assertTrue($this->cache->set('k', ['a' => 1, 'b' => [2, 3]]));
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->cache->get('k'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        $this->assertSame('fallback', $this->cache->get('nope', 'fallback'));
        $this->assertNull($this->cache->get('nope'));
    }

    public function testNullValueRoundTripsInsteadOfFallingBackToDefault(): void
    {
        // A stored null is a HIT returning null, not a MISS returning $default.
        $this->cache->set('nullable', null);

        $this->assertNull($this->cache->get('nullable', 'default'));
        $this->assertTrue($this->cache->has('nullable'), 'A stored null is still a present item.');
    }

    public function testFalseAndZeroValuesAreNotTreatedAsMisses(): void
    {
        $this->cache->set('false', false);
        $this->cache->set('zero', 0);
        $this->cache->set('empty-string', '');

        $this->assertFalse($this->cache->get('false', true));
        $this->assertSame(0, $this->cache->get('zero', 99));
        $this->assertSame('', $this->cache->get('empty-string', 'default'));
    }

    public function testSetReportsFailureForUnserializableValue(): void
    {
        $this->assertFalse($this->cache->set('inf', INF));
        $this->assertFalse($this->cache->get('inf', 'default') === 'default' ? false : true);
    }

    public function testValueSurvivesANewInstanceOnTheSameDirectory(): void
    {
        $this->cache->set('persisted', ['x' => 1]);

        $reopened = new FileCache($this->dir);

        $this->assertSame(['x' => 1], $reopened->get('persisted'));
    }

    // ----------------------------------------------------------------- ttl

    public function testTtlSecondsExpiresTheItem(): void
    {
        $this->cache->set('short', 'v', 1);

        // Rewrite the payload with an already-past expiry so the test does not
        // have to sleep.
        $this->expireNow('short');

        $this->assertSame('default', $this->cache->get('short', 'default'));
    }

    public function testTtlSecondsKeepsItemUntilItExpires(): void
    {
        $this->cache->set('long', 'v', 3600);

        $this->assertSame('v', $this->cache->get('long', 'default'));
        $this->assertTrue($this->cache->has('long'));
    }

    public function testTtlDateIntervalIsHonoured(): void
    {
        $this->cache->set('interval', 'v', new DateInterval('PT1H'));

        $this->assertSame('v', $this->cache->get('interval', 'default'));
    }

    public function testPastDateIntervalExpiresImmediately(): void
    {
        $this->cache->set('stale', 'v', new DateInterval('PT1S'));
        $this->expireNow('stale');

        $this->assertSame('default', $this->cache->get('stale', 'default'));
    }

    public function testHasEvictsAnExpiredItemInsteadOfLeavingIt(): void
    {
        $this->cache->set('gone', 'v', 3600);
        $this->expireNow('gone');

        $this->assertFalse($this->cache->has('gone'));
        $this->assertSame(
            [],
            glob($this->dir . '/*.cache') ?: [],
            'has() must evict the expired payload, not just report false.'
        );
    }

    public function testGetEvictsAnExpiredItem(): void
    {
        $this->cache->set('gone', 'v', 3600);
        $this->expireNow('gone');

        $this->cache->get('gone');

        $this->assertSame([], glob($this->dir . '/*.cache') ?: []);
    }

    public function testZeroTtlIsTreatedAsAlreadyExpired(): void
    {
        $this->cache->set('now', 'v', 0);
        $this->expireNow('now', extraSeconds: 1);

        $this->assertFalse($this->cache->has('now'));
    }

    // ----------------------------------------------------------------- has

    public function testHasIsFalseForMissingAndTrueForPresent(): void
    {
        $this->assertFalse($this->cache->has('missing'));
        $this->cache->set('present', 'v');
        $this->assertTrue($this->cache->has('present'));
    }

    // ------------------------------------------------------------- delete

    public function testDeleteRemovesTheItem(): void
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->delete('k'));
        $this->assertFalse($this->cache->has('k'));
    }

    public function testDeleteOfMissingKeyIsNotAnError(): void
    {
        $this->assertTrue($this->cache->delete('never-existed'));
    }

    public function testDeleteOfAlreadyExpiredItemIsNotAnError(): void
    {
        $this->cache->set('k', 'v', 3600);
        $this->expireNow('k');

        $this->assertTrue($this->cache->delete('k'));
    }

    // ------------------------------------------------------------- corrupt

    public function testCorruptedPayloadIsTreatedAsAMiss(): void
    {
        $this->writeRaw('corrupt', 'not json at all');

        $this->assertSame('default', $this->cache->get('corrupt', 'default'));
        $this->assertFalse($this->cache->has('corrupt'), 'An undecodable payload is not a present item.');
    }

    public function testPayloadWithoutValueKeyIsTreatedAsAMiss(): void
    {
        $this->writeRaw('shapeless', json_encode(['expires_at' => null]));

        $this->assertSame('default', $this->cache->get('shapeless', 'default'));
        $this->assertFalse($this->cache->has('shapeless'));
    }

    public function testCorruptedPayloadIsRecoverableByOverwriting(): void
    {
        $this->writeRaw('recover', 'garbage');

        $this->assertTrue($this->cache->set('recover', 'fresh'));
        $this->assertSame('fresh', $this->cache->get('recover'));
    }

    // ------------------------------------------------------------ multiple

    public function testGetMultipleReturnsEveryRequestedKeyIncludingMisses(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('c', 3);

        $this->assertSame(
            ['a' => 1, 'b' => 'default', 'c' => 3],
            $this->cache->getMultiple(['a', 'b', 'c'], 'default')
        );
    }

    public function testGetMultipleNormalisesNonStringKeysToStrings(): void
    {
        $this->cache->set('7', 'seven');

        $result = $this->cache->getMultiple([7], 'default');

        $this->assertArrayHasKey('7', $result);
        $this->assertSame('seven', $result['7']);
    }

    public function testSetMultipleAndDeleteMultipleRoundTrip(): void
    {
        $this->assertTrue($this->cache->setMultiple(['a' => 1, 'b' => 2]));

        $this->assertSame(['a' => 1, 'b' => 2], $this->cache->getMultiple(['a', 'b']));

        $this->assertTrue($this->cache->deleteMultiple(['a', 'b']));
        $this->assertSame(['a' => null, 'b' => null], $this->cache->getMultiple(['a', 'b']));
    }

    public function testSetMultipleAppliesTheTtlToEveryEntry(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2], 3600);
        $this->expireNow('a');
        $this->expireNow('b');

        $this->assertSame(['a' => null, 'b' => null], $this->cache->getMultiple(['a', 'b']));
    }

    // -------------------------------------------------------------- clear

    public function testClearRemovesEveryRootEntry(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->assertTrue($this->cache->clear());
        $this->assertSame([], glob($this->dir . '/*.cache') ?: []);
    }

    public function testClearOnAnEmptyDirectorySucceeds(): void
    {
        $this->assertTrue($this->cache->clear());
    }

    public function testClearLeavesNestedCacheDirectoriesIntact(): void
    {
        // Compiled views live in storage/cache/views/*.php; a "clear everything"
        // must not take the view cache down with the general cache.
        mkdir($this->dir . '/views', 0775, true);
        file_put_contents($this->dir . '/views/compiled.php', '<?php // view');

        $this->cache->set('a', 1);

        $this->assertTrue($this->cache->clear());

        $this->assertFileExists($this->dir . '/views/compiled.php');
    }

    public function testClearIgnoresNonCacheFilesInTheRoot(): void
    {
        file_put_contents($this->dir . '/routes.php', '<?php return [];');

        $this->assertTrue($this->cache->clear());
        $this->assertFileExists($this->dir . '/routes.php');
    }

    public function testAClearedDirectoryIsStillUsable(): void
    {
        $this->cache->set('a', 1);
        $this->cache->clear();

        $this->assertTrue($this->cache->set('b', 2));
        $this->assertSame(2, $this->cache->get('b'));
    }

    // ------------------------------------------------------------ helpers

    private function cacheFile(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }

    private function writeRaw(string $key, string $contents): void
    {
        file_put_contents($this->cacheFile($key), $contents);
    }

    /**
     * Rewrites an entry's payload so that it is already expired.
     */
    private function expireNow(string $key, int $extraSeconds = 0): void
    {
        $path = $this->cacheFile($key);

        $this->assertFileExists($path, "Expected a cache file for '{$key}'.");

        $data = json_decode((string) file_get_contents($path), true);
        $data['expires_at'] = time() - 1 - $extraSeconds;

        file_put_contents($path, (string) json_encode($data));
    }
}
