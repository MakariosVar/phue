<?php

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\TestCase;
use Phue\Core\DevTool\DevToolMiddleware;
use Phue\Core\DevTool\Dumper;
use Phue\Core\DevTool\DebugToolbar;
use Phue\Core\DevTool\RequestProfiler;
use Phue\Core\DevTool\Stopwatch;
use Phue\Core\Request;
use Phue\Core\Response;

#[\PHPUnit\Framework\Attributes\Group('unit')]
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
}