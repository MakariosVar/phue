<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Tests\Support\TestCompilerBridge;
use Phue\Tests\Support\TestComponentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Compilation-cost contract for the .phue loader pipeline.
 *
 * Compiled modules are content-addressed: the hash ends up in a URL that the
 * browser fetches over the network. That makes the loader responsible for two
 * things at once, and they pull in opposite directions:
 *
 *  1. CORRECTNESS - a module embeds its children's content hashes, so any edit
 *     anywhere below a component must change that component's hash, otherwise
 *     the parent imports a URL that 404s.
 *  2. COST - a fingerprint that "just re-checks the whole subtree" satisfies (1)
 *     by recompiling every descendant on every render, which is exactly the
 *     hot-path regression the L1 memo exists to prevent.
 *
 * These tests count compile() invocations, so they fail on a redundant build
 * (cost) just as loudly as on a stale module (correctness).
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ComponentCompilationCostTest extends TestCase
{
    private const PAGE_TEMPLATE  = '<main><b-card /></main>';
    private const CARD_TEMPLATE    = '<div class="card"><b-chip /></div>';
    private const CARD_TEMPLATE_V2 = '<div class="crud"><b-chip /></div>';
    private const CHIP_TEMPLATE_V1 = '<span class="chip">chip v1</span>';
    private const CHIP_TEMPLATE_V2 = '<span class="chip">chip v2</span>';

    private string $baseDir;

    private TestCompilerBridge $bridge;

    private ComponentLoaderRegistry $registry;

    private PhueComponentLoader $pages;

    private PhueComponentLoader $cards;

    private PhueComponentLoader $chips;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_cost_' . bin2hex(random_bytes(6));
        mkdir($this->baseDir . '/pages', 0777, true);
        mkdir($this->baseDir . '/cards', 0777, true);
        mkdir($this->baseDir . '/chips', 0777, true);

        file_put_contents(
            $this->baseDir . '/pages/Page.phue',
            '<template>' . self::PAGE_TEMPLATE . '</template>'
            . "<script>export default { name: 'Page' };</script>"
        );
        file_put_contents(
            $this->baseDir . '/cards/BCard.phue',
            '<template>' . self::CARD_TEMPLATE . '</template>'
            . "<script>export default { name: 'BCard' };</script>"
        );
        file_put_contents(
            $this->baseDir . '/chips/BChip.phue',
            '<template>' . self::CHIP_TEMPLATE_V1 . '</template>'
            . "<script>export default { name: 'BChip' };</script>"
        );

        $this->bridge = new TestCompilerBridge();
        $this->registry = new ComponentLoaderRegistry();

        $this->pages = $this->loader('pages');
        $this->cards = $this->loader('cards');
        $this->chips = $this->loader('chips');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->baseDir);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- cost

    public function testEachDistinctTemplateIsCompiledAtMostOncePerBuildPass(): void
    {
        $this->registry->moduleUrl('Page');

        $this->assertSame(
            3,
            $this->bridge->compileCount(),
            'A three-component tree must cost exactly three compiles, not one per (component, depth).'
        );
        $this->assertSame(
            [self::CHIP_TEMPLATE_V1, self::CARD_TEMPLATE, self::PAGE_TEMPLATE],
            $this->compiledTemplates(),
            'Every template in the tree compiles exactly once, leaves first.'
        );
    }

    public function testRepeatedBuildsAreServedFromTheMemoWithoutRecompiling(): void
    {
        $this->registry->moduleUrl('Page');
        $this->bridge->resetCounters();

        $this->registry->moduleUrl('Page');
        $this->registry->moduleUrl('Page');
        $this->registry->moduleUrl('BCard');
        $this->registry->moduleUrl('BChip');
        $this->pages->buildFor('Page');

        $this->assertSame(0, $this->bridge->compileCount());
    }

    public function testSsrModulesAreLeafScopedAndMemoized(): void
    {
        // The SSR module carries no child imports, so its bytes depend only on
        // its own template+script. It must therefore cost one compile, never a
        // subtree walk - this is the counterpart to the client path.
        $this->registry->buildForSsr('Page');

        $this->assertSame(
            1,
            $this->bridge->compileCount(),
            'An SSR build must not walk the component tree.'
        );
        $this->assertSame(
            [self::PAGE_TEMPLATE],
            $this->compiledTemplates()
        );

        $this->bridge->resetCounters();
        $this->registry->buildForSsr('Page');

        $this->assertSame(0, $this->bridge->compileCount());
    }

    public function testClientAndSsrMemoEntriesAreIndependent(): void
    {
        $this->registry->buildForSsr('Page');
        $this->bridge->resetCounters();

        $this->pages->buildFor('Page');

        $this->assertSame(
            [self::CHIP_TEMPLATE_V1, self::CARD_TEMPLATE, self::PAGE_TEMPLATE],
            $this->compiledTemplates(),
            'A warm SSR memo must not mask a cold client memo.'
        );
        $this->assertSame(
            [false],
            array_values(array_unique(array_column($this->bridge->recordedCalls(), 'ssr'))),
            'The client build must not be served the ssr:true compilation.'
        );
    }

    // --------------------------------------------------------- correctness

    public function testDirectChildContentChangeRebuildsChildAndParentOnce(): void
    {
        $this->registry->moduleUrl('Page');
        $this->bridge->resetCounters();

        $this->rewrite('cards/BCard.phue', self::CARD_TEMPLATE, self::CARD_TEMPLATE_V2);

        $this->registry->moduleUrl('Page');

        $this->assertSame(
            [self::CARD_TEMPLATE_V2, self::PAGE_TEMPLATE],
            $this->compiledTemplates(),
            'Only BCard and Page may rebuild; an untouched leaf must stay memoized.'
        );
        $this->assertNoTemplateCompiledTwice();
    }

    public function testGrandchildContentChangePropagatesToTheRootModule(): void
    {
        // The regression this guards: a fingerprint built from a component's own
        // stat plus its DIRECT children cannot see a grandchild edit - yet the
        // root module embeds the grandchild's hash two levels down. The root
        // then keeps importing a URL that no longer exists: a 404 on every page
        // render until the process restarts.
        $cardHashBefore = $this->registry->moduleHash('BCard');
        $urlBefore     = $this->registry->moduleUrl('Page');
        $moduleBefore  = $this->pages->buildFor('Page');

        $this->assertNotNull($cardHashBefore);
        $this->assertNotNull($urlBefore);
        $this->assertStringContainsString(
            "/assets/phue/BCard.{$cardHashBefore}.phue.js",
            (string) $moduleBefore,
            'Precondition: the root module embeds the child URL by content hash.'
        );

        $this->rewrite('chips/BChip.phue', self::CHIP_TEMPLATE_V1, self::CHIP_TEMPLATE_V2);

        $cardHashAfter = $this->registry->moduleHash('BCard');
        $urlAfter      = $this->registry->moduleUrl('Page');
        $moduleAfter   = $this->pages->buildFor('Page');

        $this->assertNotSame(
            $cardHashBefore,
            $cardHashAfter,
            'BCard embeds BChip\'s hash, so a leaf edit must change BCard\'s hash.'
        );
        $this->assertNotSame(
            $urlBefore,
            $urlAfter,
            'A grandchild edit must change the root module URL, or the parent serves a stale import.'
        );
        $this->assertStringNotContainsString(
            "/assets/phue/BCard.{$cardHashBefore}.phue.js",
            (string) $moduleAfter
        );
        $this->assertStringContainsString(
            "/assets/phue/BCard.{$cardHashAfter}.phue.js",
            (string) $moduleAfter,
            'The rebuilt root module must import the CURRENT BCard hash.'
        );
    }

    public function testGrandchildContentChangeInvalidatesEveryAncestorExactlyOnce(): void
    {
        $this->registry->moduleUrl('Page');
        $this->bridge->resetCounters();

        $this->rewrite('chips/BChip.phue', self::CHIP_TEMPLATE_V1, self::CHIP_TEMPLATE_V2);

        $this->registry->moduleUrl('Page');

        $this->assertSame(
            3,
            $this->bridge->compileCount(),
            'A leaf edit rebuilds leaf + parent + root, once each - not the whole tree twice.'
        );
        $this->assertNoTemplateCompiledTwice();
    }

    public function testMintedUrlHashIsTheHashTheAssetRouteServes(): void
    {
        // mint == serve: the URL handed to the browser must be the URL whose
        // payload the /assets/phue/{name}.{hash}.phue.js route can find.
        $url = $this->registry->moduleUrl('Page');
        $hash = $this->registry->moduleHash('Page');

        $this->assertSame("/assets/phue/Page.{$hash}.phue.js", $url);
        $this->assertSame(
            $this->pages->moduleFor('Page', (string) $hash),
            $this->registry->moduleFor('Page', (string) $hash),
            'The registry must serve exactly the module whose hash it minted.'
        );
    }

    public function testGrandchildContentChangePropagatesInsideASingleLoader(): void
    {
        // The same-loader variant of the case above, and the one that actually
        // bites in production: a project's own components almost always live in
        // ONE loader directory.
        $single = $this->singleLoaderTree();
        $registry = $single['registry'];
        $loader = $single['loader'];
        $bridge = $single['bridge'];
        $dir = $single['dir'];

        $cardHashBefore = $registry->moduleHash('BCard');
        $urlBefore     = $registry->moduleUrl('Page');
        $moduleBefore  = $loader->buildFor('Page');

        $this->assertNotNull($cardHashBefore);
        $this->assertStringContainsString(
            "/assets/phue/BCard.{$cardHashBefore}.phue.js",
            (string) $moduleBefore
        );

        $this->rewriteIn($dir, 'BChip.phue', self::CHIP_TEMPLATE_V1, self::CHIP_TEMPLATE_V2);

        $cardHashAfter = $registry->moduleHash('BCard');
        $urlAfter      = $registry->moduleUrl('Page');
        $moduleAfter   = $loader->buildFor('Page');

        $this->assertNotSame(
            $cardHashBefore,
            $cardHashAfter,
            'BCard embeds BChip\'s hash, so a leaf edit must change BCard\'s hash.'
        );
        $this->assertNotSame(
            $urlBefore,
            $urlAfter,
            'Inside one loader, a grandchild edit must still change the root module URL. '
            . 'Otherwise the parent keeps importing a BCard URL whose asset no longer exists.'
        );
        $this->assertStringContainsString(
            "/assets/phue/BCard.{$cardHashAfter}.phue.js",
            (string) $moduleAfter,
            'The rebuilt root module must import the CURRENT BCard hash.'
        );

        $this->rmTree($dir);
        unset($bridge);
    }

    public function testSingleLoaderGrandchildChangeInvalidatesEveryLevelOnce(): void
    {
        $single = $this->singleLoaderTree();
        $registry = $single['registry'];
        $bridge = $single['bridge'];
        $dir = $single['dir'];

        $registry->moduleUrl('Page');
        $bridge->resetCounters();

        $this->rewriteIn($dir, 'BChip.phue', self::CHIP_TEMPLATE_V1, self::CHIP_TEMPLATE_V2);
        $registry->moduleUrl('Page');

        $this->assertSame(
            3,
            $bridge->compileCount(),
            'One loader, three components, one leaf edit: exactly three rebuilds.'
        );

        $this->rmTree($dir);
    }

    public function testAFailedLeafCompileNeverYieldsAChildlessParentModule(): void
    {
        // A sidecar outage on the LEAF is the nastiest case, because the failure
        // happens three levels below the component that actually gets served.
        // If the parent's import loop skips null hashes, the parent silently
        // compiles WITHOUT the child its template references: valid-looking
        // bytes, stable hash, and a page rendered with a hole in it.
        $this->registry->moduleUrl('Page');

        // A warm memo would short-circuit before any compile is attempted, so
        // the outage has to hit a COLD tree - i.e. a fresh request under
        // Swoole/RoadRunner, or the first render after a deploy.
        $this->pages->clearMemo();
        $this->cards->clearMemo();
        $this->chips->clearMemo();

        $this->bridge->failNextCalls(99);

        $url = null;
        $thrown = null;

        try {
            $url = $this->registry->moduleUrl('Page');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertTrue(
            $thrown !== null || $url === null,
            'A tree that could not be compiled at all must not hand back a module URL, '
            . 'because that URL serves a parent module with a silently missing child. '
            . 'A loader must not turn a compile outage into a successful-looking render.'
        );
    }

    public function testATransientChildOutageAbortsAtTheFirstFailure(): void
    {
        $this->registry->moduleUrl('Page');

        // A warm memo would short-circuit before any compile is attempted, so
        // the outage has to hit a COLD tree - i.e. a fresh request under
        // Swoole/RoadRunner, or the first render after a deploy.
        $this->pages->clearMemo();
        $this->cards->clearMemo();
        $this->chips->clearMemo();
        $this->bridge->resetCounters();
        $this->bridge->failNextCalls(1);

        $this->assertNull(
            $this->registry->moduleUrl('Page'),
            'Fail-loud: one unresolvable child must abort the whole build, not degrade it.'
        );
        $this->assertSame(
            1,
            $this->bridge->compileCount(),
            'The build must stop at the failure instead of walking the rest of the tree.'
        );

        $this->bridge->resetCounters();

        $this->assertNotNull($this->registry->moduleUrl('Page'), 'The next healthy request must recover.');
        $this->assertSame(3, $this->bridge->compileCount(), 'Recovery must cost the normal three compiles.');
    }

    public function testACircularComponentReferenceIsReportedInsteadOfCrashing(): void
    {
        // BCard <-> BChip. The recursion now happens while RESOLVING child
        // hashes, which is upstream of the buildingStack guard, so nothing
        // stops the descent. Unbounded recursion in PHP is a native stack
        // overflow: the process dies on SIGSEGV with no stack trace, no log
        // line, and no way for the framework to report a diagnosable error.
        // One malformed .phue in a user's project would take down every worker.
        $dir = sys_get_temp_dir() . '/phue_cost_cycle_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        file_put_contents(
            $dir . '/BCard.phue',
            '<template>' . self::CARD_TEMPLATE . '</template>'
            . "<script>export default { name: 'BCard' };</script>"
        );
        file_put_contents(
            $dir . '/BChip.phue',
            '<template><span class="chip"><b-card /></span></template>'
            . "<script>export default { name: 'BChip' };</script>"
        );

        $probe = $dir . '/probe.php';
        file_put_contents($probe, <<<'PHP'
<?php
require getenv('PHUE_AUTOLOAD');
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Tests\Support\TestCompilerBridge;
use Phue\Tests\Support\TestComponentLoader;

$bridge = new TestCompilerBridge();
$registry = new ComponentLoaderRegistry();
$loader = TestComponentLoader::make(getenv('PHUE_VIEWS'), compilerBridge: $bridge);
$loader->setRegistry($registry);
$registry->addLoader($loader);

try {
    $url = $registry->moduleUrl('BCard');
    fwrite(STDOUT, "RESULT url=" . var_export($url, true) . "\n");
} catch (\Throwable $e) {
    fwrite(STDOUT, 'RESULT ' . get_class($e) . ': ' . $e->getMessage() . "\n");
}
PHP);

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $command = sprintf(
            '%s -d memory_limit=192M %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe)
        );

        $output = [];
        $exitCode = 0;
        exec(
            'PHUE_AUTOLOAD=' . escapeshellarg($autoload)
            . ' PHUE_VIEWS=' . escapeshellarg($dir)
            . ' ' . $command,
            $output,
            $exitCode
        );

        $stdout = implode("\n", $output);

        $this->assertNotSame(
            139,
            $exitCode,
            "A circular reference must not crash the process (SIGSEGV).\n{$stdout}"
        );
        $this->assertStringContainsString(
            'RESULT',
            $stdout,
            "The build must return control to the caller instead of overflowing the stack.\n{$stdout}"
        );
        $this->assertStringNotContainsString(
            'url="/assets',
            $stdout,
            "A cyclic tree is not compilable, so it must not hand back a module URL.\n{$stdout}"
        );

        $this->rmTree($dir);
    }

    public function testClearMemoIsPerLoaderAndReproducesIdenticalBytes(): void
    {
        $hashBefore = $this->registry->moduleHash('Page');
        $this->bridge->resetCounters();

        $this->pages->clearMemo();
        $this->assertSame($hashBefore, $this->registry->moduleHash('Page'));

        $this->assertSame(
            [self::PAGE_TEMPLATE],
            $this->compiledTemplates(),
            'Dropping one loader\'s memo must not evict its neighbours\' memos.'
        );
        $this->bridge->resetCounters();

        $this->pages->clearMemo();
        $this->cards->clearMemo();
        $this->chips->clearMemo();

        $this->assertSame(
            $hashBefore,
            $this->registry->moduleHash('Page'),
            'A fully cold tree must reproduce byte-identical modules, hence an identical hash.'
        );
        $this->assertSame(
            [self::CHIP_TEMPLATE_V1, self::CARD_TEMPLATE, self::PAGE_TEMPLATE],
            $this->compiledTemplates()
        );
    }

    // ------------------------------------------------------------- helpers

    private function assertNoTemplateCompiledTwice(): void
    {
        $counts = [];

        foreach ($this->bridge->recordedCalls() as $call) {
            $slot = $call['template'] . ($call['ssr'] ? ' [ssr]' : ' [dom]');
            $counts[$slot] = ($counts[$slot] ?? 0) + 1;
        }

        $this->assertSame(
            [],
            array_filter($counts, static fn (int $n): bool => $n > 1),
            'Each (template, mode) pair must be compiled at most once per pass.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function compiledTemplates(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $call): string => $call['template'],
            $this->bridge->recordedCalls()
        )));
    }

    private function loader(string $sub): PhueComponentLoader
    {
        $loader = TestComponentLoader::make(
            $this->baseDir . '/' . $sub,
            compilerBridge: $this->bridge
        );
        $loader->setRegistry($this->registry);
        $this->registry->addLoader($loader);

        return $loader;
    }

    /**
     * Page -> BCard -> BChip all inside ONE loader directory, which is how a
     * real project's own components are laid out.
     *
     * @return array{dir: string, bridge: TestCompilerBridge, registry: ComponentLoaderRegistry, loader: PhueComponentLoader}
     */
    private function singleLoaderTree(): array
    {
        $dir = sys_get_temp_dir() . '/phue_cost_single_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        file_put_contents(
            $dir . '/Page.phue',
            '<template>' . self::PAGE_TEMPLATE . '</template>'
            . "<script>export default { name: 'Page' };</script>"
        );
        file_put_contents(
            $dir . '/BCard.phue',
            '<template>' . self::CARD_TEMPLATE . '</template>'
            . "<script>export default { name: 'BCard' };</script>"
        );
        file_put_contents(
            $dir . '/BChip.phue',
            '<template>' . self::CHIP_TEMPLATE_V1 . '</template>'
            . "<script>export default { name: 'BChip' };</script>"
        );

        $bridge = new TestCompilerBridge();
        $registry = new ComponentLoaderRegistry();
        $loader = TestComponentLoader::make($dir, compilerBridge: $bridge);
        $loader->setRegistry($registry);
        $registry->addLoader($loader);

        return ['dir' => $dir, 'bridge' => $bridge, 'registry' => $registry, 'loader' => $loader];
    }

    private function rewriteIn(string $dir, string $file, string $from, string $to): void
    {
        $path = $dir . '/' . $file;
        $sizeBefore = (int) filesize($path);

        $this->assertSame(
            strlen($from),
            strlen($to),
            'Fixture templates must be byte-length identical; otherwise size alone proves nothing.'
        );

        file_put_contents($path, str_replace($from, $to, (string) file_get_contents($path)));

        clearstatcache(true, $path);
        touch($path, time() + 10);
        clearstatcache(true, $path);

        $this->assertSame(
            $sizeBefore,
            (int) filesize($path),
            'The fixture must keep its byte length; otherwise size alone proves nothing.'
        );
    }

    /**
     * Overwrites a component with a variant that keeps the byte length and gets
     * a bumped mtime, so a size-based shortcut cannot accidentally satisfy the
     * change detection.
     */
    private function rewrite(string $relativePath, string $from, string $to): void
    {
        $path = $this->baseDir . '/' . $relativePath;
        $sizeBefore = (int) filesize($path);

        $this->assertSame(
            strlen($from),
            strlen($to),
            'Fixture templates must be byte-length identical; otherwise size alone proves nothing.'
        );

        file_put_contents(
            $path,
            str_replace($from, $to, (string) file_get_contents($path))
        );

        clearstatcache(true, $path);
        touch($path, time() + 10);
        clearstatcache(true, $path);

        $this->assertSame(
            $sizeBefore,
            (int) filesize($path),
            'The fixture must keep its byte length; otherwise size alone proves nothing.'
        );
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
}
