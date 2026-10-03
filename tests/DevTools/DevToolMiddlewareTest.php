<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\DevTool\DevToolMiddleware;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use RuntimeException;

#[Group('unit')]
class DevToolMiddlewareTest extends TestCase
{
    private RequestProfiler $profiler;
    private DebugToolbar $toolbar;
    private DevToolMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->toolbar = new DebugToolbar();
        $this->middleware = new DevToolMiddleware($this->profiler, $this->toolbar);
    }

    public function testMiddlewareInjectsToolbarIntoHtmlResponse(): void
    {
        $handler = fn (Request $req): Response => new Response(
            '<html><body><h1>Hello</h1></body></html>',
            200,
            ['Content-Type' => 'text/html']
        );

        $request = new Request('GET', '/');
        $response = ($this->middleware)($request, $handler);

        $this->assertStringContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertStringContainsString('</body>', $response->getContent());
        // Toolbar should be injected right before </body> (possibly with closing div in between)
        $posToolbar = strpos($response->getContent(), 'phue-debug-toolbar');
        $posBody = strpos($response->getContent(), '</body>');
        $this->assertGreaterThan($posToolbar, $posBody);
    }

    public function testMiddlewareSkipsJsonResponses(): void
    {
        $handler = fn (Request $req): Response => Response::json(['data' => 'test']);

        $request = new Request('GET', '/api/data');
        $response = ($this->middleware)($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type') ?? '');
    }

    public function testMiddlewareSkipsXhrRequests(): void
    {
        $handler = fn (Request $req): Response => new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);

        $request = new Request('GET', '/api/data', [], [], ['X-Requested-With' => 'XMLHttpRequest']);
        $response = ($this->middleware)($request, $handler);

        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
    }

    public function testMiddlewarePreservesStatusCode(): void
    {
        $handler = fn (Request $req): Response => new Response('Not Found', 404, ['Content-Type' => 'text/html']);

        $request = new Request('GET', '/missing');
        $response = ($this->middleware)($request, $handler);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMiddlewarePreservesOriginalContentWithoutBodyTag(): void
    {
        $handler = fn (Request $req): Response => new Response(
            'Plain text response without html tags',
            200,
            ['Content-Type' => 'text/plain']
        );

        $request = new Request('GET', '/');
        $response = ($this->middleware)($request, $handler);

        // No </body> tag, so no injection
        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertSame('Plain text response without html tags', $response->getContent());
    }

    public function testMiddlewareProfilingStartsBeforeHandler(): void
    {
        $this->profiler->start();
        $beforeMemory = $this->profiler->getMemoryUsage();

        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);
        $request = new Request('GET', '/');
        ($this->middleware)($request, $handler);

        // Profiler should have stopped and collected data
        $duration = $this->profiler->getDurationMs();
        $this->assertGreaterThanOrEqual(0.0, $duration);
    }

    // ------------------------------------------------------------ recording

    public function testPipelineSpanIsRecorded(): void
    {
        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        ($this->middleware)(new Request('GET', '/'), $handler);

        $labels = array_column($this->profiler->getSpans(), 'label');
        $this->assertContains('pipeline', $labels);
    }

    public function testNestedWorkInsideTheHandlerIsRecordedUnderThePipelineSpan(): void
    {
        $handler = function (Request $req): Response {
            $this->profiler->measure('inner', static function (): void {
                usleep(200);
            });

            return new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);
        };

        ($this->middleware)(new Request('GET', '/'), $handler);

        $spans = $this->profiler->getSpans();
        $labels = array_column($spans, 'label');

        $this->assertContains('inner', $labels);
        $this->assertSame(2, $spans[0]['depth']);

        $pipeline = null;
        foreach ($spans as $span) {
            if ($span['label'] === 'pipeline') {
                $pipeline = $span;
            }
        }

        $this->assertNotNull($pipeline);
        $this->assertGreaterThan(0.0, $pipeline['exclusive_ms']);
        $this->assertGreaterThan($pipeline['exclusive_ms'], $pipeline['duration_ms']);
    }

    public function testRequestContextIsRecorded(): void
    {
        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        ($this->middleware)(new Request('GET', '/orders?page=2'), $handler);

        $context = $this->profiler->getContext();

        $this->assertSame('GET', $context['method']);
        $this->assertSame(200, $context['status']);
        $this->assertStringContainsString('/orders', $context['uri']);
        $this->assertStringContainsString('page=2', $context['route']);
        // The query must be reported once, never appended twice.
        $this->assertSame(1, substr_count((string) $context['route'], 'page=2'));
    }

    public function testResponseByteCountExcludesTheInjectedToolbar(): void
    {
        $body = '<html><body>hello</body></html>';
        $handler = fn (Request $req): Response => new Response($body, 200, ['Content-Type' => 'text/html']);

        ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertSame(strlen($body), $this->profiler->getContextValue('response_bytes'));
    }

    public function testErrorStatusIsStillProfiled(): void
    {
        $handler = fn (Request $req): Response => new Response('<html><body>gone</body></html>', 410, ['Content-Type' => 'text/html']);

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertSame(410, $this->profiler->getContextValue('status'));
        $this->assertSame(410, $response->getStatusCode());
    }

    public function testToolbarRenderCostIsExposedAsAHeader(): void
    {
        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $header = $response->getHeader('X-Phue-Debug-Toolbar-Ms');

        $this->assertNotNull($header);
        $this->assertMatchesRegularExpression('/^\d+\.\d{3}$/', (string) $header);
    }

    public function testToolbarDoesNotCountItselfAsApplicationWork(): void
    {
        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        ($this->middleware)(new Request('GET', '/'), $handler);

        // Opening a span around the render would make the panel report a permanently
        // unbalanced stack and would charge the reporter to the reported.
        $this->assertSame(0, $this->profiler->getOpenSpanCount());
        $this->assertNotContains('devtool.render', array_column($this->profiler->getSpans(), 'label'));
    }

    // ------------------------------------------------------------ isolation

    public function testSecondRequestDoesNotInheritTheFirstRequestsSpans(): void
    {
        $first = function (Request $req): Response {
            $this->profiler->measure('first-only', static function (): void {
                usleep(100);
            });

            return new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);
        };
        $second = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        ($this->middleware)(new Request('GET', '/one'), $first);
        ($this->middleware)(new Request('GET', '/two'), $second);

        $labels = array_column($this->profiler->getSpans(), 'label');

        $this->assertNotContains('first-only', $labels);
        $this->assertSame(['pipeline'], $labels);
        $this->assertSame('/two', $this->profiler->getContextValue('uri'));
    }

    // ---------------------------------------------------------- injection

    public function testInjectsBeforeClosingHtmlWhenBodyTagIsAbsent(): void
    {
        $handler = fn (Request $req): Response => new Response(
            '<html><h1>Fragment</h1></html>',
            200,
            ['Content-Type' => 'text/html']
        );

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertStringContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertLessThan(strpos($response->getContent(), '</html>'), strpos($response->getContent(), 'phue-debug-toolbar'));
    }

    public function testInjectsIntoHtmlFragmentWithoutAnyClosingTag(): void
    {
        $handler = fn (Request $req): Response => new Response(
            '<div id="app">partial</div>',
            200,
            ['Content-Type' => 'text/html']
        );

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertStringContainsString('phue-debug-toolbar', $response->getContent());
        $this->assertStringContainsString('<div id="app">partial</div>', $response->getContent());
    }

    public function testInjectsBeforeTheLastClosingBodyTag(): void
    {
        $handler = fn (Request $req): Response => new Response(
            '<html><body><pre>&lt;/body&gt;</pre></body></html>',
            200,
            ['Content-Type' => 'text/html']
        );

        $content = ($this->middleware)(new Request('GET', '/'), $handler)->getContent();

        $this->assertSame(1, substr_count($content, 'class="phue-dt-root'));
        $this->assertGreaterThan(strpos($content, '&lt;/body&gt;'), strpos($content, 'phue-debug-toolbar'));
        $this->assertGreaterThan(strpos($content, 'phue-debug-toolbar'), strrpos($content, '</body>'));
    }

    public function testEmptyBodyIsNotInjected(): void
    {
        $handler = fn (Request $req): Response => new Response('', 204, ['Content-Type' => 'text/html']);

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertSame('', $response->getContent());
    }

    public function testBinaryContentTypeIsNotInjected(): void
    {
        $handler = fn (Request $req): Response => new Response(
            "\x89PNG\r\n\x1a\n<html></html>",
            200,
            ['Content-Type' => 'image/png']
        );

        $response = ($this->middleware)(new Request('GET', '/logo.png'), $handler);

        $this->assertStringNotContainsString('phue-debug-toolbar', $response->getContent());
    }

    public function testXhtmlIsInjected(): void
    {
        $handler = fn (Request $req): Response => new Response(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
            200,
            ['Content-Type' => 'application/xhtml+xml']
        );

        $response = ($this->middleware)(new Request('GET', '/'), $handler);

        $this->assertStringContainsString('phue-debug-toolbar', $response->getContent());
    }

    // ------------------------------------------------------------ failures

    public function testHandlerExceptionPropagatesAndLeavesNoOpenSpans(): void
    {
        $handler = static function (Request $req): Response {
            throw new RuntimeException('handler blew up');
        };

        try {
            ($this->middleware)(new Request('GET', '/'), $handler);
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('handler blew up', $e->getMessage());
        }

        $this->assertSame(0, $this->profiler->getOpenSpanCount());
    }

    public function testOptionsAreForwardedToTheToolbar(): void
    {
        $middleware = new DevToolMiddleware($this->profiler, $this->toolbar, ['json' => false]);
        $handler = fn (Request $req): Response => new Response('<html><body></body></html>', 200, ['Content-Type' => 'text/html']);

        $response = ($middleware)(new Request('GET', '/'), $handler);

        $this->assertStringContainsString('Timeline', $response->getContent());
        $this->assertStringNotContainsString('Raw report', $response->getContent());
    }
}
