<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\UI\PhueComponent;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Modules\DevTool\ProfilingComponentLoader;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Tests\Support\TestComponentLoader;

/**
 * The renderer mints module hashes straight off the loader, so this decorator is what
 * makes the dominant cost on a component-heavy page visible at all.
 */
#[Group('integration')]
class ProfilingComponentLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $componentsDir;
    private RequestProfiler $profiler;
    private PhueComponentLoader $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_profiling_loader_' . uniqid();
        $this->componentsDir = $this->tmpDir . '/components';
        mkdir($this->componentsDir, 0777, true);

        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
        $this->source = TestComponentLoader::make($this->componentsDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmTree($this->tmpDir);
    }

    private function writePhue(string $name, string $template = '<template><button /></template>'): void
    {
        file_put_contents(
            $this->componentsDir . '/' . $name . '.phue',
            $template . "\n<script>export default {}</script>"
        );
    }

    private function decorating(): ProfilingComponentLoader
    {
        return new ProfilingComponentLoader(
            componentsDir: $this->source->getComponentsDir(),
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: self::bridge(),
            profiler: $this->profiler,
            source: $this->source
        );
    }

    private static function bridge(): \Phue\Core\UI\TemplateCompilerBridge
    {
        return new \Phue\Core\UI\TemplateCompilerBridge(
            socketPath: '/tmp/phue-does-not-exist.sock',
            cache: null
        );
    }

    // ------------------------------------------------------------- identity

    public function testIsAComponentLoader(): void
    {
        $this->assertInstanceOf(PhueComponentLoader::class, $this->decorating());
    }

    public function testReadsTheSameComponentDirectoryAsItsSource(): void
    {
        $this->assertSame($this->source->getComponentsDir(), $this->decorating()->getComponentsDir());
    }

    public function testIndexesTheSameComponentsAsItsSource(): void
    {
        $this->writePhue('PhButton');
        $loader = $this->decorating();

        $this->assertTrue($loader->hasComponent('PhButton'));
        $this->assertFalse($loader->hasComponent('PhMissing'));
    }

    public function testInheritsTheSourceMemoization(): void
    {
        // Replacing the loader with an instance that starts with empty memo tables would
        // not only lose the measurements: the page would get dramatically slower, and the
        // slowdown would be invisible in a report that only counts what the profiler sees.
        $this->writePhue('PhButton');
        $loader = $this->decorating();

        $reflection = new \ReflectionProperty(PhueComponentLoader::class, 'clientMemo');

        $this->assertSame(
            $reflection->getValue($this->source),
            $reflection->getValue($loader),
            'The decorating loader must start with its source memo tables'
        );
    }

    // ------------------------------------------------------------- recording

    public function testGetStructureIsRecorded(): void
    {
        $this->writePhue('PhButton');

        $this->decorating()->getStructure('PhButton');

        $span = $this->profiler->getSpans()[0];

        $this->assertSame('loader.getStructure', $span['label']);
        $this->assertSame('PhButton', $span['meta']['component']);
    }

    public function testLoadReturnsTheSameComponentAsTheSource(): void
    {
        $this->writePhue('PhButton', '<template><button>Go</button></template>');

        $expected = $this->source->load('PhButton');
        $actual = $this->decorating()->load('PhButton');

        $this->assertInstanceOf(PhueComponent::class, $expected);
        $this->assertInstanceOf(PhueComponent::class, $actual);
        $this->assertSame($expected->view->getTemplate(), $actual->view->getTemplate());
    }

    public function testEveryResolutionMethodEmitsALabel(): void
    {
        $this->writePhue('PhButton');
        $loader = $this->decorating();

        $loader->getStructure('PhButton');
        $loader->moduleHash('PhButton');
        $loader->rawModuleHash('PhButton');
        $loader->buildForSsr('PhButton');
        $loader->load('PhButton');

        $labels = array_column($this->profiler->getSpans(), 'label');

        foreach ([
            'loader.getStructure',
            'loader.moduleHash',
            'loader.rawModuleHash',
            'loader.buildForSsr',
        ] as $expected) {
            $this->assertContains($expected, $labels);
        }

        // load() deliberately shares the registry's label. The registry dispatches into
        // this method, so a label of its own would report one load as two rows and the
        // rollup could not be trusted to say how many loads a request made.
        $this->assertContains('loader.load', $labels);
        $this->assertNotContains('loader.component.load', $labels);
    }

    public function testRepeatedResolutionIsCounted(): void
    {
        $this->writePhue('PhButton');
        $loader = $this->decorating();

        for ($i = 0; $i < 40; $i++) {
            $loader->getStructure('PhButton');
        }

        $this->assertSame(40, $this->profiler->getAggregates()['loader.getStructure']['count']);
    }

    public function testMissingComponentStillClosesItsSpan(): void
    {
        $this->decorating()->getStructure('NoSuchComponent');

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(1, $this->profiler->getSpans());
    }

    public function testUninstrumentedMethodsAreInheritedUnchanged(): void
    {
        $this->writePhue('PhButton');

        $loader = $this->decorating();

        $this->assertTrue($loader->hasComponent('PhButton'));
        $this->assertSame([], $this->profiler->getSpans(), 'hasComponent() must not be measured');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->rmTree($item) : @unlink($item);
        }

        @rmdir($dir);
    }
}
