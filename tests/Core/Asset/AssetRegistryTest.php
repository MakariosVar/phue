<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;

/**
 * Unit tests for the AssetRegistry: source registration, prefix matching and the
 * full path-traversal / null-byte / symlink security surface.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AssetRegistryTest extends TestCase
{
    private string $rootDir;
    private string $sourceDir;
    private string $siblingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = sys_get_temp_dir() . '/phue_asset_' . uniqid();
        $this->sourceDir = $this->rootDir . '/assets';
        $this->siblingDir = $this->rootDir . '/assets-secret';

        mkdir($this->sourceDir . '/sub', 0755, true);
        mkdir($this->siblingDir, 0755, true);

        file_put_contents($this->sourceDir . '/app.js', 'console.log("app");');
        file_put_contents($this->sourceDir . '/theme.css', 'body{margin:0}');
        file_put_contents($this->sourceDir . '/data.json', '{"ok":true}');
        file_put_contents($this->sourceDir . '/sub/nested.txt', 'nested');
        file_put_contents($this->sourceDir . '/../secret.txt', 'TOP_SECRET');
        file_put_contents($this->siblingDir . '/flag.txt', 'NOT_THIS');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTree($this->rootDir);
    }

    public function testRegistersSourceWithNormalizedPrefix(): void
    {
        $registry = new AssetRegistry();
        $registry->registerSource('assets', $this->sourceDir);

        $sources = $registry->getSources();
        $this->assertArrayHasKey('/assets', $sources);
        $this->assertSame(realpath($this->sourceDir), $sources['/assets']);
    }

    public function testThrowsForMissingSourceDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AssetRegistry())->registerSource('/assets', $this->rootDir . '/does-not-exist');
    }

    public function testResolvePathReturnsRealFileInsideSource(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js')
        );
    }

    public function testResolvePathFindsNestedFile(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/sub/nested.txt'),
            $registry->resolvePath('/assets/sub/nested.txt')
        );
    }

    public function testResolvePathReturnsNullForUnregisteredPrefix(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/public/app.js'));
        $this->assertNull($registry->resolvePath('/v1/assets/app.js'));
    }

    public function testResolvePathReturnsNullForMissingFile(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/missing.js'));
    }

    public function testResolvePathReturnsNullForDirectoryRequest(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets'));
        $this->assertNull($registry->resolvePath('/assets/sub'));
    }

    public function testResolvePathStripsQueryAndFragment(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js?v=1.2.3#top')
        );
    }

    public function testResolvePathBlocksParentDirectoryTraversal(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/../secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/sub/../../secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/..%2fsecret.txt'));
    }

    public function testResolvePathBlocksEtcPasswdEscape(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/../../etc/passwd'));
    }

    public function testResolvePathBlocksUrlEncodedDotSegments(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/%2e%2e/secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%2e%2e%2fsecret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%2E%2E/secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%252e%252e%252fsecret.txt'));
    }

    public function testResolvePathBlocksBackslashEncodedTraversal(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/..%5c..%5csecret.txt'));
        $this->assertNull($registry->resolvePath('/assets/..\\..\\secret.txt'));
    }

    public function testResolvePathBlocksSiblingPrefixExploit(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        // /assets → base dir; /assets-  shares the string prefix /assets but is a sibling.
        // Must NOT resolve into assets-secret regardless of traversal spelling.
        $this->assertNull($registry->resolvePath('/assets/../assets-secret/flag.txt'));
        $this->assertNull($registry->resolvePath('/assets/../../assets-secret/flag.txt'));
    }

    public function testResolvePathBlocksSymlinkEscape(): void
    {
        @symlink($this->rootDir . '/secret.txt', $this->sourceDir . '/leak.js');

        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        if (is_link($this->sourceDir . '/leak.js')) {
            $this->assertNull($registry->resolvePath('/assets/leak.js'));
        } else {
            $this->markTestSkipped('symlink not available');
        }
    }

    public function testResolvePathHandlesNullByteGracefully(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath("/assets/app.js\0"));
        $this->assertNull($registry->resolvePath("/assets/app.js\0.xyz"));
    }

    public function testResolvePathAllowsDotDotThatNormalizesInsideBase(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/sub/../app.js')
        );
    }

    public function testLongestPrefixMatchWins(): void
    {
        $vendorDir = $this->rootDir . '/vendor';
        mkdir($vendorDir);
        file_put_contents($vendorDir . '/vue.js', 'VUE_ESM');

        $registry = (new AssetRegistry())
            ->registerSource('/assets', $this->sourceDir)
            ->registerSource('/assets/vendor', $vendorDir);

        $this->assertSame(
            realpath($vendorDir . '/vue.js'),
            $registry->resolvePath('/assets/vendor/vue.js')
        );
        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js')
        );
    }

    public function testAddScriptDeduplicatesByUrl(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/app.js')->addScript('/assets/app.js');

        $this->assertCount(1, $registry->getScripts());
    }

    public function testAddScriptKeepsModuleFlag(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/vendor/vue.js', true);

        $scripts = $registry->getScripts();
        $this->assertSame(
            ['url' => '/assets/vendor/vue.js', 'isModule' => true],
            $scripts[0]
        );
    }

    public function testAddScriptSameUrlKeepsFirstModuleFlag(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/app.js', true)->addScript('/assets/app.js', false);

        $scripts = $registry->getScripts();
        $this->assertCount(1, $scripts);
        $this->assertTrue($scripts[0]['isModule']);
    }

    public function testAddStyleDeduplicatesByUrl(): void
    {
        $registry = (new AssetRegistry())->addStyle('/assets/theme.css')->addStyle('/assets/theme.css');

        $this->assertCount(1, $registry->getStyles());
    }

    public function testGetScriptsMaintainsRegistrationOrder(): void
    {
        $registry = (new AssetRegistry())
            ->addScript('/assets/first.js')
            ->addScript('/assets/second.js')
            ->addScript('/assets/third.js', true);

        $urls = array_column($registry->getScripts(), 'url');
        $this->assertSame(['/assets/first.js', '/assets/second.js', '/assets/third.js'], $urls);
    }

    public function testGlobalScriptsAreReturnedBeforeRequestScripts(): void
    {
        $registry = (new AssetRegistry())
            ->addGlobalScript('/assets/bootstrap.js', true)
            ->addGlobalScript('/assets/vendor.js', false)
            ->addScript('/assets/page.js')
            ->addScript('/assets/other.js');

        $urls = array_column($registry->getScripts(), 'url');
        $this->assertSame(
            ['/assets/bootstrap.js', '/assets/vendor.js', '/assets/page.js', '/assets/other.js'],
            $urls
        );
    }

    public function testAddGlobalScriptDeduplicatesByUrl(): void
    {
        $registry = (new AssetRegistry())
            ->addGlobalScript('/assets/bootstrap.js', true)
            ->addGlobalScript('/assets/bootstrap.js', false);

        $scripts = $registry->getGlobalScripts();
        $this->assertCount(1, $scripts);
        $this->assertTrue($scripts[0]['isModule']);
    }

    public function testAddScriptDoesNotDuplicateGlobalScript(): void
    {
        $registry = (new AssetRegistry())
            ->addGlobalScript('/assets/shared.js', true)
            ->addScript('/assets/shared.js');

        $this->assertCount(1, $registry->getScripts());
        $this->assertCount(0, $registry->getRequestScripts());
    }

    public function testBeginRequestClearsOnlyRequestScripts(): void
    {
        $registry = (new AssetRegistry())
            ->addGlobalScript('/assets/explorer.js', true)
            ->addScript('/assets/page.js');

        $registry->beginRequest();

        $this->assertSame([], $registry->getRequestScripts());
        $this->assertCount(1, $registry->getGlobalScripts());
    }

    public function testReEnteringSameScriptAfterBeginRequestRegistersItAgain(): void
    {
        $registry = (new AssetRegistry())
            ->addGlobalScript('/assets/explorer.js', true);

        // Request #1
        $registry->beginRequest();
        $registry->addScript('/assets/page.js');

        // Request #2 is a re-visit of the same page in a persistent Swoole process.
        // The old pool-based offset logic made this a no-op; the two-pool design
        // must register the page module again so SPA navigation can mount it.
        $registry->beginRequest();
        $registry->addScript('/assets/page.js');

        $requestScripts = $registry->getRequestScripts();
        $this->assertCount(1, $requestScripts);
        $this->assertSame('/assets/page.js', $requestScripts[0]['url']);
    }

    public function testBeginRequestDoesNotMergeGlobalScriptsIntoRequestPool(): void
    {
        $registry = (new AssetRegistry())->addGlobalScript('/assets/bootstrap.js', true);

        $registry->beginRequest();

        $this->assertSame([], $registry->getRequestScripts());
        $urls = array_column($registry->getScripts(), 'url');
        $this->assertSame(['/assets/bootstrap.js'], $urls);
    }

    public function testAddRequestStyleAppearsInRequestAndMergedStyles(): void
    {
        $registry = new AssetRegistry();
        $registry->addRequestStyle('/assets/app/styles/main.css');

        $this->assertContains('/assets/app/styles/main.css', $registry->getRequestStyles());
        $this->assertContains('/assets/app/styles/main.css', $registry->getStyles());
    }

    public function testAddRequestStyleDeduplicatesAgainstGlobalStyles(): void
    {
        $registry = (new AssetRegistry())->addStyle('/assets/app/styles/theme.css');
        $registry->addRequestStyle('/assets/app/styles/theme.css');

        $this->assertCount(0, $registry->getRequestStyles(), 'Request pool must skip global-collision style');
        $this->assertCount(1, $registry->getStyles());
        $this->assertSame('/assets/app/styles/theme.css', $registry->getStyles()[0]);
    }

    public function testAddRequestStyleDeduplicatesWithinRequestPool(): void
    {
        $registry = new AssetRegistry();
        $registry->addRequestStyle('/assets/app/styles/main.css')
                 ->addRequestStyle('/assets/app/styles/main.css');

        $this->assertCount(1, $registry->getRequestStyles());
        $this->assertCount(1, $registry->getStyles());
    }

    public function testGetStylesMergesGlobalBeforeRequest(): void
    {
        $registry = (new AssetRegistry())
            ->addStyle('/assets/app/global.css')
            ->addRequestStyle('/assets/app/page.css');

        $this->assertSame(
            ['/assets/app/global.css', '/assets/app/page.css'],
            $registry->getStyles()
        );
    }

    public function testBeginRequestClearsRequestStylesOnly(): void
    {
        $registry = (new AssetRegistry())
            ->addStyle('/assets/app/global.css')
            ->addRequestStyle('/assets/app/page.css');

        $registry->beginRequest();

        $this->assertSame([], $registry->getRequestStyles(), 'Request pool must be flushed');
        $this->assertCount(1, $registry->getStyles(), 'Global styles survive');
        $this->assertSame('/assets/app/global.css', $registry->getStyles()[0]);
    }

    public function testReenterSameRequestStyleAfterBeginRequestRegistersAgain(): void
    {
        $registry = new AssetRegistry();

        // Request #1: style registered for the page shell.
        $registry->beginRequest();
        $registry->addRequestStyle('/assets/app/styles/page.css');
        $this->assertCount(1, $registry->getRequestStyles());

        // Request #2: re-visit of the same page in a persistent Swoole process.
        // Without the two-pool design beginRequest would have silently dropped
        // the re-declared style, starving the new shell of its CSS.
        $registry->beginRequest();
        $registry->addRequestStyle('/assets/app/styles/page.css');

        $this->assertCount(1, $registry->getRequestStyles());
        $this->assertSame('/assets/app/styles/page.css', $registry->getRequestStyles()[0]);
    }

    public function testGlobalStylesSurviveBeginRequest(): void
    {
        $registry = (new AssetRegistry())->addStyle('/assets/app/global.css');

        $registry->beginRequest();

        $this->assertCount(1, $registry->getStyles());
        $this->assertSame('/assets/app/global.css', $registry->getStyles()[0]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}