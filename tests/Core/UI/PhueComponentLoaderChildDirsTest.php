<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Core\UI\PhueComponent;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Tests for the lazy recursive child-directory lookup in PhueComponentLoader.
 *
 * Components living in child directories (e.g. `Docs/Nested.phue`) must be
 * discoverable by their basename (tag-derived PascalCase name), while the
 * flat fast path stays backwards-compatible, the shallowest path wins on
 * collision, `..` traversal stays blocked, and a stale index rebuilds on miss.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueComponentLoaderChildDirsTest extends TestCase
{
    private const DIR = __DIR__ . '/ComponentsFixture';

    private string $componentsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = self::DIR . '/child-views';
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }
        mkdir($this->componentsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }

        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rmTree($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * Writes a .phue component at an arbitrary relative path inside the
     * components dir (creates parent directories on demand).
     */
    private function writeComponentAt(string $relativePath, string $template, array $state = [], string $script = ''): void
    {
        $target = $this->componentsDir . '/' . $relativePath;
        @mkdir(dirname($target), 0777, true);

        $sections = [
            "<php>return ['title' => '" . basename($relativePath, '.phue') . "', 'state' => " . var_export($state, true) . "];</php>",
            "<template>{$template}</template>",
        ];
        if ($script !== '') {
            $sections[] = "<script>{$script}</script>";
        }

        file_put_contents($target, implode("\n", $sections));
    }

    // ------------------------------------------------------------- tests

    public function testFlatComponentStillLoads(): void
    {
        $this->writeComponentAt('Flat.phue', '<div>Flat content</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertTrue($loader->hasComponent('Flat'));

        $component = $loader->load('Flat');
        $this->assertInstanceOf(PhueComponent::class, $component);
        $this->assertSame('Flat', $component->title);
        $this->assertStringContainsString('<div>Flat content</div>', $component->view->getTemplate());
    }

    public function testNestedComponentFoundByBasename(): void
    {
        $this->writeComponentAt('Docs/Nested.phue', '<div>Nested content</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        // Tag-derived PascalCase name (no slash) must resolve into the child dir
        $this->assertTrue($loader->hasComponent('Nested'));

        $structure = $loader->getStructure('Nested');
        $this->assertNotNull($structure);
        $this->assertStringContainsString('Docs/Nested.phue', str_replace(DIRECTORY_SEPARATOR, '/', $structure['filePath'] ?? ''));
        $this->assertStringContainsString('<div>Nested content</div>', $structure['template'] ?? '');
    }

    public function testNestedComponentEvaluatesPhpState(): void
    {
        // The php block must reference the $message PARAM expression — the
        // writeComponentAt helper var_exports state, which would freeze a
        // literal value into the file at write time.
        $target = $this->componentsDir . '/Docs/Stateful.phue';
        @mkdir(dirname($target), 0777, true);
        file_put_contents(
            $target,
            "<php>return ['title' => 'Stateful', 'state' => ['message' => \$message ?? 'none']];</php>\n"
            . "<template><div>{{message}}</div></template>"
        );
        $loader = TestComponentLoader::make($this->componentsDir);

        $component = $loader->load('Stateful', ['message' => 'hello world']);

        $this->assertSame('Stateful', $component->title);
        $this->assertSame('hello world', $component->view->getState()['message'] ?? null);
    }

    public function testContentForNestedReturnsScript(): void
    {
        $this->writeComponentAt(
            'Docs/Scripted.phue',
            '<div>Scripted</div>',
            [],
            'export default { name: "Scripted" };'
        );
        $loader = TestComponentLoader::make($this->componentsDir);

        $script = $loader->contentFor('Scripted');

        $this->assertNotNull($script);
        $this->assertStringContainsString('name: "Scripted"', $script);
    }

    public function testScriptHashVerificationForNested(): void
    {
        $script = 'export default { name: "Hashed" };';
        $this->writeComponentAt('Docs/Hashed.phue', '<div>Hashed</div>', [], $script);
        $loader = TestComponentLoader::make($this->componentsDir);

        $hash = substr(sha1($script), 0, 8);

        $this->assertSame($script, $loader->scriptFor('Hashed', $hash));
        $this->assertNull($loader->scriptFor('Hashed', 'deadbeef'), 'Wrong hash must not return the script');
    }

    public function testShallowestPathWinsOnCollision(): void
    {
        $this->writeComponentAt('Docs/Dupe.phue', '<div>Docs dupe</div>');
        $this->writeComponentAt('Sub/Deep/Dupe.phue', '<div>Deep dupe</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $structure = $loader->getStructure('Dupe');

        $this->assertNotNull($structure);
        $resolved = str_replace(DIRECTORY_SEPARATOR, '/', $structure['filePath'] ?? '');
        $this->assertStringContainsString('Docs/Dupe.phue', $resolved, 'Depth-1 path must win over depth-2');
        $this->assertStringContainsString('<div>Docs dupe</div>', $structure['template'] ?? '');
    }

    public function testDirectSlashNameStillResolves(): void
    {
        $this->writeComponentAt('Docs/Nested.phue', '<div>Nested content</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertTrue($loader->hasComponent('Docs/Nested'));

        $component = $loader->load('Docs/Nested');
        $this->assertSame('Nested', $component->title);
    }

    public function testDotDotTraversalStillBlocked(): void
    {
        $this->writeComponentAt('Docs/Nested.phue', '<div>Nested</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertFalse($loader->hasComponent('../outside'));
        $this->assertFalse($loader->hasComponent('Docs/../../outside'));
        $this->assertNull($loader->getStructure('../outside'));
    }

    public function testUnknownComponentNotFound(): void
    {
        $this->writeComponentAt('Docs/Nested.phue', '<div>Nested</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertFalse($loader->hasComponent('Missing'));
        $this->assertNull($loader->getStructure('Missing'));
    }

    public function testIndexIsARequestScopedSnapshotRebuiltOnTheBoundary(): void
    {
        // CONTRACT CHANGE (was: testStaleIndexRebuildsOnMiss).
        //
        // The old contract was "a miss invalidates the index and rebuilds it".
        // That is precisely the defect this test used to lock in: hasComponent()
        // is called once per component-tag occurrence by scanTemplate(), so on
        // /phui it produced ~2,900 misses, each walking the component directory
        // twice and throwing a RuntimeException. That accounted for ~1.3s of a
        // 1.7s response.
        //
        // The index is now a per-request snapshot, rebuilt once per request.
        // Freshness therefore comes from the request boundary, not from a miss.
        $this->writeComponentAt('Docs/Early.phue', '<div>Early</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        // Warm up: builds the index exactly once.
        $this->assertFalse($loader->hasComponent('Missing'));

        $this->writeComponentAt('Docs/Late.phue', '<div>Late</div>');

        // Inside the same request the snapshot must NOT change: that stability
        // is what keeps resolution O(1) instead of O(tags * loaders * files).
        $this->assertFalse(
            $loader->hasComponent('Late'),
            'the index must be a stable snapshot for the duration of a request'
        );

        // The next request picks it up.
        $loader->beginRequest();

        $this->assertTrue($loader->hasComponent('Late'));
        $this->assertSame('Late', $loader->load('Late')->title);
    }

    public function testIndexRebuildsAcrossNestedDirectoriesOnTheBoundary(): void
    {
        // The regression guard for a directory-mtime based rebuild heuristic:
        // creating a file in a SUBDIRECTORY does not change the parent's mtime,
        // so watching only the root directory can never see it. This asserts
        // the boundary rebuild is unconditional and therefore immune to that.
        $this->writeComponentAt('Docs/Shallow.phue', '<div>Shallow</div>');
        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertTrue($loader->hasComponent('Shallow'));

        $this->writeComponentAt('Docs/deep/Deeper/Deeper.phue', '<div>Deeper</div>');
        $loader->beginRequest();

        $this->assertTrue($loader->hasComponent('Deeper'));
        $this->assertSame('Deeper', $loader->load('Deeper')->title);
    }

    public function testDiscovererFindsNestedComponentsThroughRegistry(): void
    {
        $this->writeComponentAt('Docs/DocSidebar.phue', '<nav>Nested sidebar</nav>');
        $this->writeComponentAt('Flat.phue', '<div>Flat</div>');

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(TestComponentLoader::make($this->componentsDir));

        $discoverer = new ComponentDiscoverer($registry);

        $this->assertSame(
            ['DocSidebar', 'Flat'],
            $discoverer->scanTemplate('<div><doc-sidebar /><flat /></div>'),
            'Components in child dirs must be discovered by tag through the registry'
        );
        $this->assertSame(['DocSidebar'], $discoverer->scanTemplate('<doc-sidebar />'));
    }
}
