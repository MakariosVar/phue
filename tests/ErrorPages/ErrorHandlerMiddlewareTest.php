<?php

namespace Phue\Tests\ErrorPages;

use PHPUnit\Framework\TestCase;
use Phue\Core\ErrorPages\ErrorHandlerMiddleware;
use Phue\Core\ErrorPages\ErrorPageRenderer;
use Phue\Core\Request;
use Phue\Core\Response;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[\PHPUnit\Framework\Attributes\Group('unit')]
class ErrorHandlerMiddlewareTest extends TestCase
{
    private ErrorPageRenderer $renderer;
    private LoggerInterface $logger;
    private ErrorHandlerMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ErrorPageRenderer();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->middleware = new ErrorHandlerMiddleware($this->renderer, $this->logger, debug: true);
    }

    public function testMiddlewareCatchesExceptionAndReturnsJsonForApi(): void
    {
        $handler = fn (Request $req): Response => throw new RuntimeException('API error');

        $request = new Request('GET', '/api/data', [], [], ['Accept' => 'application/json']);
        $response = ($this->middleware)($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type') ?? '');
        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('API error', $body['error']);
        $this->assertSame('RuntimeException', $body['class'] ?? null);
    }

    public function testMiddlewareCatchesExceptionAndReturnsHtmlForBrowser(): void
    {
        $handler = fn (Request $req): Response => throw new RuntimeException('Browser error');

        $request = new Request('GET', '/page');
        $response = ($this->middleware)($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type') ?? '');
        $this->assertStringContainsString('RuntimeException: Browser error', $response->getContent());
        $this->assertStringContainsString('class="trace"', $response->getContent());
    }

    public function testMiddlewareReturnsTraceInDebugModeForApi(): void
    {
        $handler = fn (Request $req): Response => throw new RuntimeException('Debug error');

        $request = new Request('GET', '/api/data', [], [], ['Accept' => 'application/json']);
        $response = ($this->middleware)($request, $handler);

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('class', $body);
        $this->assertArrayHasKey('file', $body);
        $this->assertArrayHasKey('line', $body);
    }

    public function testMiddlewareGenericMessageInProductionModeForApi(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer, $this->logger, debug: false);
        $handler = fn (Request $req): Response => throw new RuntimeException('Secret details');

        $request = new Request('GET', '/api/data', [], [], ['Accept' => 'application/json']);
        $response = ($middleware)($request, $handler);

        $body = json_decode($response->getContent(), true);
        $this->assertSame('Internal Server Error', $body['error']);
        $this->assertArrayNotHasKey('class', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function testMiddlewareDoesNotLogInNormalFlow(): void
    {
        // Normal handler (no exception) - logger should not be called
        $this->logger->expects($this->never())->method('error');

        $handler = fn (Request $req): Response => new Response('OK');
        $request = new Request('GET', '/');

        $response = ($this->middleware)($request, $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareCatchesAnyThrowable(): void
    {
        $handler = fn (Request $req): Response => throw new \Error('PHP Error');

        $request = new Request('GET', '/');
        $response = ($this->middleware)($request, $handler);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testXhrRequestsGetJsonResponse(): void
    {
        $handler = fn (Request $req): Response => throw new RuntimeException('XHR error');

        $request = new Request('GET', '/api/data');
        $request = new Request('GET', '/api/data', [], [], ['X-Requested-With' => 'XMLHttpRequest']);
        $response = ($this->middleware)($request, $handler);

        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type') ?? '');
    }
}