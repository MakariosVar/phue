<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Modules\DevTool\ProfilingComponentLoader;
use Phue\Modules\DevTool\ProfilingComponentLoaderRegistry;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Tests\Support\TestComponentLoader;

/**
 * The profiling registry exists to answer one question a hot page cannot answer without
 * it: how many times did component resolution re-enter, and for which components.
 */
#[Group('integration')]
class ProfilingComponentLoaderRegistryTest extends TestCase
{
    private string $tmpDir;
    private string $componentsDir;
    private RequestProfiler $profiler;
    private ProfilingComponentLoaderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_profiling_registry_' . uniqid();
        $this->componentsDir = $this->tmpDir . '/components';
        mkdir($this->componentsDir, 0777, true);

        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
        $this->registry = new ProfilingComponentLoaderRegistry(null, $this->profiler);
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

    private function loader(): PhueComponentLoader
    {
        return TestComponentLoader::make($this->componentsDir);
    }

    private function withLoader(): self
    {
        $this->registry->addLoader(fn (): PhueComponentLoader => $this->loader());

        return $this;
    }

    /**
     * Add the instrumented loader, the way DevToolsModule wires it in a real request.
     *
     * @return self
     */
    private function withProfilingLoader(): self
    {
        $this->registry->addLoader(fn (): PhueComponentLoader => new ProfilingComponentLoader(
            componentsDir: $this->componentsDir,
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: new \Phue\Core\UI\TemplateCompilerBridge(
                socketPath: '/tmp/phue-does-not-exist.sock',
                cache: null
            ),
            profiler: $this->profiler
        ));

        return $this;
    }

    // ------------------------------------------------- one operation, one label

    public function testLoadDispatchedToTheLoaderIsOneRowNotTwo(): void
    {
        $this->writePhue('PhButton');
        $this->withProfilingLoader();

        $this->registry->load('PhButton');
        $this->registry->load('PhButton');

        $aggregates = $this->profiler->getAggregates();

        // The registry and the loader are both instrumented, so a shared label folds the
        // nested frame. Two labels instead of one would make the rollup claim two
        // operations per load and send the profiling exercise after a cost that is not
        // in the request.
        $this->assertArrayHasKey('loader.load', $aggregates);
        $this->assertSame(2, $aggregates['loader.load']['count']);
        $this->assertArrayNotHasKey('loader.component.load', $aggregates);
    }

    public function testDispatchedLoadKeepsTheWorkChargedToTheOperation(): void
    {
        $this->writePhue('PhButton');
        $this->withProfilingLoader();

        $this->registry->load('PhButton');

        $aggregates = $this->profiler->getAggregates();
        $partition = array_sum(array_column($aggregates, 'exclusive_ms'));

        // Folding a frame must move its time to the parent, never delete it.
        $this->assertEqualsWithDelta($aggregates['loader.load']['total_ms'], $partition, 0.01);
    }

    public function testLoadThatBypassesTheRegistryIsStillCounted(): void
    {
        $this->writePhue('PhButton');

        // ComponentRenderer calls the loader directly on some paths, which is exactly why
        // the loader is instrumented at all. A top-level frame has nothing to fold into
        // and must appear on its own.
        $loader = new ProfilingComponentLoader(
            componentsDir: $this->componentsDir,
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: new \Phue\Core\UI\TemplateCompilerBridge(
                socketPath: '/tmp/phue-does-not-exist.sock',
                cache: null
            ),
            profiler: $this->profiler
        );

        $loader->load('PhButton');

        $this->assertSame(1, $this->profiler->getAggregates()['loader.load']['count']);
    }

    public function testTheRequestBoundaryReportsWhatTheChangeCheckFound(): void
    {
        $this->writePhue('PhButton');

        $loader = new ProfilingComponentLoader(
            componentsDir: $this->componentsDir,
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: new \Phue\Core\UI\TemplateCompilerBridge(
                socketPath: '/tmp/phue-does-not-exist.sock',
                cache: null
            ),
            profiler: $this->profiler
        );

        $loader->beginRequest();

        // "Why is this page slow" starts with "was anything rebuilt", and the rebuild is now a
        // decision Hiit makes. A tool that cannot see the decision cannot explain the cost.
        $this->assertArrayHasKey(
            'hiit.check',
            $this->profiler->getAggregates(),
            'the change check must show up as timed work, it is the gate on the whole memo reuse'
        );

        // The span is the record that the check ran, whether or not anything changed.
        $this->assertArrayHasKey('hiit.check', $this->profiler->getAggregates());
    }

    public function testTheRequestBoundarySaysWhenDetectionIsOff(): void
    {
        $this->writePhue('PhButton');

        $loader = new ProfilingComponentLoader(
            componentsDir: $this->componentsDir,
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: new \Phue\Core\UI\TemplateCompilerBridge(
                socketPath: '/tmp/phue-does-not-exist.sock',
                cache: null
            ),
            profiler: $this->profiler
        );

        $loader->setHiit(null);
        $loader->beginRequest();

        $messages = array_column($this->profiler->getDiagnostics(), 'message');

        $this->assertNotEmpty(
            preg_grep('/change detection is off/', $messages),
            'a site that turned detection off needs to be able to see that it did'
        );
    }

    public function testOnlyTheColdBoundaryIsReported(): void
    {
        $this->writePhue('PhButton');

        $loader = $this->profilingLoader();

        // The first boundary on a freshly built loader legitimately finds everything changed:
        // there are no memo tables yet, so there is nothing to compare against. Reporting it is
        // correct and happens once.
        $loader->beginRequest();
        $this->assertCount(1, $this->profiler->getDiagnostics());

        // From then on the steady state has to be silent. A note per loader per request turned
        // the report into two hundred identical lines and buried the requests that mattered.
        $loader->beginRequest();
        $loader->beginRequest();
        $loader->beginRequest();

        $this->assertCount(
            1,
            $this->profiler->getDiagnostics(),
            'a long-lived worker must not add a note on every request'
        );
    }

    public function testAChangedRequestReportsHowManyAndNamesSomeOfThem(): void
    {
        $this->writePhue('PhButton');

        $loader = $this->profilingLoader();
        $loader->beginRequest();

        $this->writePhue('PhButton');
        $loader->beginRequest();

        $diagnostics = $this->profiler->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertStringContainsString('1 component', $diagnostics[0]['message']);
        $this->assertSame(['PhButton'], $diagnostics[0]['context']['changed']);
    }

    public function testDetectionBeingOffIsReported(): void
    {
        $this->writePhue('PhButton');

        $loader = $this->profilingLoader();
        $loader->setHiit(null);
        $loader->beginRequest();

        $diagnostics = $this->profiler->getDiagnostics();

        $this->assertNotSame([], $diagnostics, 'a site that turned detection off needs to see that it did');
        $this->assertStringContainsString('off', $diagnostics[0]['message']);
    }

    private function profilingLoader(): ProfilingComponentLoader
    {
        return new ProfilingComponentLoader(
            componentsDir: $this->componentsDir,
            persistentCache: null,
            viewsCacheDir: null,
            compilerBridge: new \Phue\Core\UI\TemplateCompilerBridge(
                socketPath: '/tmp/phue-does-not-exist.sock',
                cache: null
            ),
            profiler: $this->profiler
        );
    }

    // ------------------------------------------------------------ pass-through

    public function testIsAComponentLoaderRegistry(): void
    {
        $this->assertInstanceOf(ComponentLoaderRegistry::class, $this->registry);
    }

    public function testInheritedLoaderListIsReplayed(): void
    {
        $this->writePhue('PhButton');
        $real = new ComponentLoaderRegistry();
        $real->addLoader(fn (): PhueComponentLoader => $this->loader());

        // $loaders is private on the parent, so a decorator built from scratch would
        // resolve nothing at all. getLoaders() is what makes the subclass viable.
        foreach ($real->getLoaders() as $candidate) {
            $this->registry->addLoader($candidate);
        }

        $this->assertCount(1, $this->registry->getLoaders());
        $this->assertTrue($this->registry->hasComponent('PhButton'));
    }

    public function testHasComponentIsInheritedAndUnaffected(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->assertTrue($this->registry->hasComponent('PhButton'));
        $this->assertFalse($this->registry->hasComponent('PhMissing'));
    }

    public function testLoadReturnsTheSameComponentAsAnUnprofiledRegistry(): void
    {
        $this->writePhue('PhButton', '<template><button>Go</button></template>');

        $plain = new ComponentLoaderRegistry();
        $plain->addLoader(fn (): PhueComponentLoader => $this->loader());
        $expected = $plain->load('PhButton');

        $this->withLoader();
        $actual = $this->registry->load('PhButton');

        $this->assertNotNull($expected);
        $this->assertNotNull($actual);
        $this->assertSame($expected->view->getTemplate(), $actual->view->getTemplate());
    }

    public function testUnknownComponentStillReturnsNull(): void
    {
        $this->withLoader();

        $this->assertNull($this->registry->load('NoSuchComponent'));
        $this->assertNull($this->registry->moduleHash('NoSuchComponent'));
    }

    public function testBeginRequestReachesTheInheritedLoaders(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->registry->beginRequest();

        $this->assertTrue($this->registry->hasComponent('PhButton'));
    }

    // -------------------------------------------------------------- recording

    public function testModuleHashIsRecordedWithTheComponentName(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->registry->moduleHash('PhButton');

        $spans = $this->profiler->getSpans();

        $this->assertNotEmpty($spans);
        $this->assertSame('loader.moduleHash', $spans[0]['label']);
        $this->assertSame('PhButton', $spans[0]['meta']['component']);
    }

    public function testLoadIsRecorded(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->registry->load('PhButton');

        $this->assertSame('loader.load', $this->profiler->getSpans()[0]['label']);
    }

    public function testRepeatedResolutionIsCounted(): void
    {
        // The regression this instrument exists to expose: a page that resolves the same
        // component graph many times per request, invisible without a call count.
        $this->writePhue('PhButton');
        $this->withLoader();

        for ($i = 0; $i < 25; $i++) {
            $this->registry->moduleHash('PhButton');
        }

        $this->assertSame(25, $this->profiler->getAggregates()['loader.moduleHash']['count']);
    }

    public function testPerComponentCostIsDistinguishable(): void
    {
        // A deliberately heavy template, so the ranking is decided by real work rather
        // than by microsecond noise between two identical compiles.
        $heavy = '<template>' . str_repeat('<span class="cell">column</span>', 400) . '</template>';
        $this->writePhue('PhButton');
        $this->writePhue('PhCard', $heavy);
        $this->withLoader();

        $this->registry->moduleHash('PhButton');
        $this->registry->moduleHash('PhButton');
        $this->registry->moduleHash('PhCard');

        $spans = $this->profiler->getSpans();
        $this->assertCount(3, $spans);

        $top = $this->profiler->getTopSpans(1);

        $this->assertSame('PhCard', $top[0]['meta']['component']);
        $this->assertSame('PhButton', $spans[0]['meta']['component']);
    }

    public function testDifferentOperationsAreAggregatedSeparately(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->registry->moduleHash('PhButton');
        $this->registry->load('PhButton');

        $labels = array_keys($this->profiler->getAggregates());

        $this->assertContains('loader.moduleHash', $labels);
        $this->assertContains('loader.load', $labels);
    }

    public function testFailedResolutionStillClosesItsSpan(): void
    {
        $this->withLoader();

        $this->assertNull($this->registry->moduleHash('Nope'));

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertCount(1, $this->profiler->getSpans());
    }

    public function testCollectorStaysCleanAcrossSuccessiveRequests(): void
    {
        $this->writePhue('PhButton');
        $this->withLoader();

        $this->registry->moduleHash('PhButton');
        $this->profiler->start();
        $this->registry->moduleHash('PhButton');

        $this->assertSame(1, $this->profiler->getAggregates()['loader.moduleHash']['count']);
    }

    // ------------------------------------------------- loader re-pointing

    public function testLoaderIsRepointedAtTheProfilingRegistryAfterInvocation(): void
    {
        // The framework registers loaders as closures that capture the ORIGINAL registry
        // and call setRegistry() on themselves. A decorator that copies those closures
        // verbatim is silently bypassed the first time one runs: the loader re-points
        // itself at the unprofiled registry, and PhueComponentLoader::buildFor() then
        // resolves every child hash through it — so the profiler reports zero calls for
        // the exact operation it exists to measure.
        $this->writePhue('PhButton');
        $loader = $this->loader();

        $plain = new ComponentLoaderRegistry();

        // Reproduce the framework's shape: the closure installs the plain registry.
        $plain->addLoader(static function () use ($loader, $plain): PhueComponentLoader {
            $loader->setRegistry($plain);

            return $loader;
        });

        $decorator = new ProfilingComponentLoaderRegistry(null, $this->profiler);

        // The wrapper is the module's job; build it the same way here.
        foreach ($plain->getLoaders() as $candidate) {
            $decorator->addLoader(static function () use ($candidate, $decorator): ?PhueComponentLoader {
                $loader = $candidate();
                if ($loader instanceof PhueComponentLoader) {
                    $loader->setRegistry($decorator);
                }

                return $loader;
            });
        }

        $this->assertTrue($decorator->hasComponent('PhButton'));
        $this->assertSame($decorator, $this->registryOf($loader));
        $this->assertNotSame($plain, $this->registryOf($loader));
    }

    public function testUnwrappedLoaderFallsBackToThePlainRegistry(): void
    {
        // The failure mode being guarded: without the wrapper the loader ends up pointing
        // somewhere the profiler cannot see.
        $this->writePhue('PhButton');
        $loader = $this->loader();

        $plain = new ComponentLoaderRegistry();
        $plain->addLoader(static function () use ($loader, $plain): PhueComponentLoader {
            $loader->setRegistry($plain);

            return $loader;
        });

        $copied = new ProfilingComponentLoaderRegistry(null, $this->profiler);
        foreach ($plain->getLoaders() as $candidate) {
            $copied->addLoader($candidate);
        }

        $copied->hasComponent('PhButton');

        $this->assertSame($plain, $this->registryOf($loader));
    }

    /**
     * Read the registry a loader is currently pointed at.
     */
    private function registryOf(PhueComponentLoader $loader): ?object
    {
        return (new \ReflectionProperty(PhueComponentLoader::class, 'registry'))->getValue($loader);
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
