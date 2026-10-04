<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\ComponentDiscoverer;
use Phue\Tests\Support\CountingComponentLoader;
use Phue\Tests\Support\TestCompilerBridge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Walking the dependency graph must not evaluate components.
 *
 * resolveTransitiveDependencies() needs one thing from each node: its template, so it can
 * scan for child tags. It was calling ComponentLoaderRegistry::load() to get it, and load()
 * evaluates the component's `<php>` block — arbitrary application PHP, run for all 142
 * nodes of a PhUI page on every request, on the strength of a value that the parsed
 * structure already holds.
 *
 * On /phui that was the whole of `loader.load`: 48.9ms of a 221ms request, of which a
 * single component — Pages/PhUI, a 241-line block plus a MarkdownParser pass — accounted
 * for 40ms. Rendering nothing at all cost exactly as much as rendering 22 components,
 * because the evaluation is per-node rather than per-render.
 *
 * The template is identical either way: evaluateStructure() constructs the View with
 * `template: $structure['template']`. Swapping the call removes the evaluation without
 * changing a byte of what gets scanned.
 */
#[Group('unit')]
final class ComponentDiscovererCostTest extends TestCase
{
    private string $root;
    private string $appDir;
    private string $libDir;
    private CountingComponentLoader $app;
    private CountingComponentLoader $lib;
    private ComponentLoaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phue_disc_cost_' . bin2hex(random_bytes(6));
        $this->appDir = $this->root . '/app';
        $this->libDir = $this->root . '/lib';

        mkdir($this->appDir, 0777, true);
        mkdir($this->libDir, 0777, true);

        $this->app = new CountingComponentLoader(componentsDir: $this->appDir, compilerBridge: new TestCompilerBridge());
        $this->lib = new CountingComponentLoader(componentsDir: $this->libDir, compilerBridge: new TestCompilerBridge());

        $this->registry = new ComponentLoaderRegistry();
        $this->registry->addLoader($this->app);
        $this->registry->addLoader($this->lib);
        $this->app->setRegistry($this->registry);
        $this->lib->setRegistry($this->registry);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->root)) {
            return;
        }

        foreach (glob($this->root . '/*/*.phue') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->appDir);
        @rmdir($this->libDir);
        @rmdir($this->root);
    }

    // ------------------------------------------------------ the defect this file pins

    public function testWalkingTheGraphDoesNotEvaluateAnyComponent(): void
    {
        $this->writeGraph();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies('Root');

        $this->assertGreaterThan(4, count($graph), 'fixture should resolve a multi-node graph');
        $this->assertSame(
            0,
            $this->app->loadCalls + $this->lib->loadCalls,
            'Resolving a dependency graph ran component <php> blocks. The graph needs templates, not evaluations.'
        );
    }

    public function testTheGraphIsStillCompleteWithoutEvaluating(): void
    {
        $this->writeGraph();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies('Root');

        $this->assertSame(
            ['App1', 'App2', 'Lib1', 'Lib2', 'Root'],
            $this->sortedKeys($graph),
            'Dropping the evaluation dropped nodes with it.'
        );
    }

    public function testTheHashesAreStillMintedForEveryNode(): void
    {
        $this->writeGraph();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies('Root');

        foreach ($graph as $name => $hash) {
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{8}$/',
                $hash,
                "Node {$name} came back without a usable module hash."
            );
        }
    }

    // ------------------------------------------------------------------- guards

    public function testACycleIsStillRejectedLoudly(): void
    {
        $this->write('Root', $this->appDir, '<div><App1 /></div>');
        $this->write('App1', $this->appDir, '<div><Root /></div>');

        $discoverer = new ComponentDiscoverer($this->registry);

        // Reading structures instead of evaluating components must not have softened this:
        // a cycle has no valid module, so it has to surface rather than resolve to a
        // truncated graph.
        $this->expectException(\Phue\Core\UI\Exceptions\CircularComponentReferenceException::class);

        $discoverer->resolveTransitiveDependencies('Root');
    }

    public function testTheRootItselfIsMintedAndNotSkipped(): void
    {
        $this->writeGraph();

        $discoverer = new ComponentDiscoverer($this->registry);
        $graph = $discoverer->resolveTransitiveDependencies('Root');

        $this->assertArrayHasKey('Root', $graph);
    }

    // ----------------------------------------------------------------------- helpers

    private function writeGraph(): void
    {
        $this->write('Root', $this->appDir, '<main><App1 /><Lib1 /></main>');
        $this->write('App1', $this->appDir, '<div><App2 /><Lib2 /></div>');
        $this->write('App2', $this->appDir, '<span>leaf</span>');
        $this->write('Lib1', $this->libDir, '<div><Lib2 /></div>');
        $this->write('Lib2', $this->libDir, '<span>leaf</span>');
    }

    private function write(string $name, string $dir, string $template): void
    {
        file_put_contents(
            $dir . '/' . $name . '.phue',
            "<template>\n{$template}\n</template>\n<script>export default {}</script>\n"
        );
    }

    /**
     * @param array<string, string> $graph
     * @return list<string>
     */
    private function sortedKeys(array $graph): array
    {
        $keys = array_keys($graph);
        sort($keys);

        return $keys;
    }
}