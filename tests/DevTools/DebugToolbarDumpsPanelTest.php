<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\Database\QueryBuilder;
use Phue\Core\Debug\AsyncDumper;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\Panels\DumpsPanel;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Tests\Support\SqliteFactory;

/**
 * Tests for the DevTool "Dumps" tab.
 *
 * The tab is fed exclusively by the request-scoped AsyncDumper, rendered on the
 * server. That is the reason a dump used to be invisible: the value was written
 * straight to the process output instead of through the dumper, so nothing ever
 * reached this panel. These tests keep the path honest end to end — from the
 * query builder, through the dumper, into the HTML a developer actually reads.
 *
 * @package Phue\Tests\DevTools
 */
#[Group('unit')]
final class DebugToolbarDumpsPanelTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    private ?Container $previousContainer = null;

    private Container $container;

    private AsyncDumper $dumper;

    private DebugToolbar $toolbar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->container          = new Container();
        $this->dumper             = new AsyncDumper();

        $dumper = $this->dumper;

        $this->container->set(AsyncDumper::class, static fn(): AsyncDumper => $dumper);
        Container::setInstance($this->container);

        $this->toolbar = new DebugToolbar();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------- visibility

    public function testDumpsTabAppearsWhenSomethingWasDumped(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString('dumps-panel', $html);
        $this->assertStringContainsString('>Dumps</button>', $html);
    }

    public function testDumpsTabIsAbsentWhenNothingWasDumped(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('dumps-panel', $html);
        $this->assertStringNotContainsString('No dumps collected', $html);
    }

    public function testQueryReportIsVisibleInThePanel(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString('SELECT * FROM `users` WHERE `id` = ?', $html);
        $this->assertStringContainsString('compiled', $html);
        $this->assertStringContainsString('SELECT * FROM `users` WHERE `id` = 1', $html);
    }

    public function testQueryDumpCarriesItsLabelAndGroup(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString('<span class="badge bg-primary">query</span>', $html);
        $this->assertStringContainsString('<span class="badge bg-secondary">query</span>', $html);
    }

    public function testEachDumpIsNumberedAndShowsItsRequestId(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString('Dump #0', $html);
        $this->assertStringContainsString($this->dumper->getRequestId(), $html);
    }

    // ------------------------------------------------------------- value rendering

    public function testArrayValuesAreRenderedWithRealLineBreaks(): void
    {
        $this->dumpQuery();

        $value = $this->dumpValueBlock($this->render());

        $this->assertStringContainsString("[\n", $value, 'array values must break across lines, not print \\n');
        $this->assertStringNotContainsString('[\n', $value);
    }

    public function testStringValuesInsideAnArrayKeepTheirContent(): void
    {
        $this->dumpQuery();

        $value = $this->dumpValueBlock($this->render());

        $this->assertStringContainsString('driver', $value);
        $this->assertStringContainsString('sqlite', $value);
    }

    public function testNestedValuesAccumulateIndentation(): void
    {
        $this->dumper->dump(['plan' => [['id' => 1]]]);

        $value = $this->plainDumpValueBlock($this->render());

        $this->assertSame(
            [
                '[',
                '  plan => [',
                '    [',
                '      id => 1',
                '    ]',
                '  ]',
                ']',
            ],
            explode("\n", $value),
            'A child has to sit further right than its parent, or the nesting is invisible.'
        );
    }

    public function testListValuesDropTheirNumericKeys(): void
    {
        $this->dumper->dump(['bindings' => [1, 2]]);

        $this->assertSame(
            ['[', '  bindings => [', '    1,', '    2', '  ]', ']'],
            explode("\n", $this->plainDumpValueBlock($this->render()))
        );
    }

    public function testSparseArraysKeepTheirKeys(): void
    {
        $this->dumper->dump(['rows' => [3 => 'third']]);

        $this->assertSame(
            ['[', '  rows => [', '    3 => "third"', '  ]', ']'],
            explode("\n", $this->plainDumpValueBlock($this->render()))
        );
    }

    public function testEmptyContainersRenderInline(): void
    {
        $this->dumper->dump(['list' => [], 'map' => ['a' => 1]]);

        $this->assertSame(
            ['[', '  list => [],', '  map => [', '    a => 1', '  ]', ']'],
            explode("\n", $this->plainDumpValueBlock($this->render()))
        );
    }

    public function testObjectsRenderWithTheirClassNameAndIndent(): void
    {
        // The dumper normally flattens objects before they get here, so the panel's
        // object branch is exercised directly to keep it honest.
        $html = DumpsPanel::render([
            'dumps' => [[
                'value'      => ['row' => $this->anonymousRow()],
                'label'      => '',
                'group'      => 'default',
                'time'       => microtime(true),
                'trace'      => [],
                'request_id' => 'test',
            ]],
        ]);

        $this->assertSame(
            ['[', '  row => Phue\\Tests\\DevTools\\DumpPanelRow {', '    id => 7', '  }', ']'],
            explode("\n", $this->plainDumpValueBlock($html))
        );
    }

    public function testSanitizedObjectsArriveLabelledWithTheirClass(): void
    {
        $this->dumper->dump(['row' => $this->anonymousRow()]);

        $value = $this->plainDumpValueBlock($this->render());

        $this->assertStringContainsString('__class => "Phue\\Tests\\DevTools\\DumpPanelRow"', $value);
        $this->assertStringContainsString('id => 7', $value);
    }

    public function testDeepNestingIsTruncatedRatherThanRenderedForever(): void
    {
        $value = 'leaf';
        for ($i = 0; $i < 20; $i++) {
            $value = ['level' => $value];
        }

        $this->dumper->dump($value);

        $this->assertStringContainsString(
            '[Max depth reached]',
            $this->plainDumpValueBlock($this->render())
        );
    }

    public function testValuesAreHtmlEscaped(): void
    {
        $this->dumper->dump(['markup' => '<script>alert(1)</script>']);

        $html = $this->render();
        $value = $this->dumpValueBlock($html);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $value);
        $this->assertStringContainsString('&lt;script&gt;', $value);
    }

    // ------------------------------------------------------------- traces

    public function testTraceIsRenderedOnTheServer(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString(__FILE__, $html, 'the trace must be readable without JavaScript');
    }

    public function testTraceFramesAreQualifiedWithTheClassSeparator(): void
    {
        $this->dumpQuery();

        $trace = $this->dumpTraceBlock($this->render());

        $this->assertStringContainsString(
            QueryBuilder::class . '::dd()',
            $trace,
            'class and function must be separated, not glued into AsyncDumperdd'
        );
    }

    public function testTraceOmitsTheFrameworkFramesItFilteredOut(): void
    {
        $this->dumpQuery();

        $trace = $this->dumpTraceBlock($this->render());

        $this->assertStringNotContainsString('AsyncDumper::getTrace', $trace);
        $this->assertStringNotContainsString('/vendor/', $trace);
    }

    public function testTraceStaysHiddenUntilItIsToggled(): void
    {
        $this->dumpQuery();

        $html = $this->render();

        $this->assertStringContainsString('toggle-trace', $html);
        $this->assertMatchesRegularExpression(
            '/<pre class="dump-trace[^"]*d-none[^"]*"><code>.+<\/code><\/pre>/s',
            $html,
            'the trace must be collapsed by default and still carry its content'
        );
    }

    // ------------------------------------------------------------- script

    public function testEmittedPanelScriptParses(): void
    {
        $this->dumpQuery();

        $scripts = $this->extractInlineScripts($this->render());

        $this->assertNotEmpty($scripts, 'the dumps panel is expected to ship an inline script');

        foreach ($scripts as $index => $script) {
            if (str_contains($script, 'dump-trace') || str_contains($script, 'dumps-panel')) {
                $this->assertScriptParses($script, (string) $index);
            }
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * An object the dumper and the panel both have to cope with.
     *
     * A named class on purpose: an anonymous one carries its file and line in the
     * class name, which would make the rendering assertion depend on the test's
     * own location.
     *
     * @return DumpPanelRow Object with one public property
     */
    private function anonymousRow(): DumpPanelRow
    {
        return new DumpPanelRow();
    }

    /**
     * Records a query report through the builder under test.
     *
     * @return void
     */
    private function dumpQuery(): void
    {
        $connection = SqliteFactory::memory();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute("INSERT INTO users (id, name) VALUES (1, 'Makarios')");

        $connection->table('users')->where('id', '=', 1)->dd();
    }

    /**
     * Renders the toolbar for a finished request.
     *
     * @return string Toolbar HTML
     */
    private function render(): string
    {
        $profiler = new RequestProfiler(new Stopwatch());

        $profiler->start();
        $profiler->addContext([
            'method'         => 'GET',
            'uri'            => '/docs',
            'route'          => '/docs',
            'status'         => 200,
            'response_bytes' => 4096,
        ]);
        $profiler->stop();

        return $this->toolbar->render($profiler, 12.0);
    }

    /**
     * Extracts the rendered value block of the first dump.
     *
     * @param string $html Toolbar HTML
     * @return string Value block content
     */
    private function dumpValueBlock(string $html): string
    {
        if (preg_match('#<div class="dump-value[^"]*"[^>]*>\s*<pre[^>]*><code>(.*?)</code></pre>#s', $html, $matches) !== 1) {
            $this->fail('the panel did not render a dump value block');
        }

        return $matches[1];
    }

    /**
     * Extracts the rendered value block of the first dump, without any markup.
     *
     * Layout is the point of these assertions, so the comparison happens on the
     * text a developer reads, not on the spans wrapped around it.
     *
     * @param string $html Toolbar HTML
     * @return string Plain-text value block
     */
    private function plainDumpValueBlock(string $html): string
    {
        return html_entity_decode(strip_tags($this->dumpValueBlock($html)), ENT_QUOTES);
    }

    /**
     * Extracts the rendered trace block of the first dump.
     *
     * @param string $html Toolbar HTML
     * @return string Trace block content
     */
    private function dumpTraceBlock(string $html): string
    {
        if (preg_match('#<pre class="dump-trace[^"]*"[^>]*><code>(.*?)</code></pre>#s', $html, $matches) !== 1) {
            $this->fail('the panel did not render a trace block');
        }

        return $matches[1];
    }

    /**
     * Extracts every inline script from the given HTML.
     *
     * @param string $html HTML to scan
     * @return array<int, string> Script bodies
     */
    private function extractInlineScripts(string $html): array
    {
        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches) === false) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', $matches[1]),
                static fn (string $script): bool => $script !== ''
            )
        );
    }

    /**
     * Parses the given script with a real JavaScript parser.
     *
     * @param string $script Script body
     * @param string $label Identifier used in the failure message
     * @return void
     */
    private function assertScriptParses(string $script, string $label): void
    {
        $bun = $this->findParser();

        if ($bun === null) {
            $this->markTestSkipped('no JavaScript parser available in this environment');
        }

        $file = (string) tempnam(sys_get_temp_dir(), 'phue_dumps_script_') . '.js';
        file_put_contents($file, $script);
        $this->temporaryFiles[] = $file;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process    = proc_open(
            [$bun, 'build', '--target=browser', $file, '--outfile', $file . '.out.js'],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $this->markTestSkipped('could not start the JavaScript parser');
        }

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            $this->fail(
                sprintf("the dumps panel script does not parse (%s):\n%s", $label, trim($stderr))
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Locates a JavaScript parser.
     *
     * @return string|null Parser path, or null when none is installed
     */
    private function findParser(): ?string
    {
        foreach (['bun', 'node', 'deno'] as $candidate) {
            $path = trim((string) @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate))));

            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }
}

/**
 * A named object for the panel's object branch.
 */
final class DumpPanelRow
{
    /**
     * Public property, so reflection finds it.
     */
    public int $id = 7;
}
