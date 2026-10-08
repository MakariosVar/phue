<?php

namespace Phue\Tests\ErrorPages;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use RuntimeException;

#[\PHPUnit\Framework\Attributes\Group('integration')]
class ApplicationErrorHandlingTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure cache directory exists for route caching
        $basePath = dirname(__DIR__, 2);
        $cacheDir = $basePath . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $this->app = new Application($basePath);
        $this->isolateApplicationDatabase($this->app);
    }

    public function testApplicationReturnsHtmlErrorPageForUnhandledException(): void
    {
        $this->app->getRouter()->get('/boom', fn () => throw new RuntimeException('Boom!'));

        $request = new Request('GET', '/boom');
        $response = $this->app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $content = $response->getContent();
        // Should return HTML error page, not raw error message
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('An uncaught exception occurred while processing your request.', $content);
    }

    public function testApplicationReturnsJsonErrorForApiException(): void
    {
        $this->app->getRouter()->get('/api/boom', fn () => throw new RuntimeException('API Boom!'));

        $request = new Request('GET', '/api/boom', [], [], ['Accept' => 'application/json']);
        $response = $this->app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body);
    }

    public function testApplication404DoesNotCrash(): void
    {
        $request = new Request('GET', '/this-route-does-not-exist-'.uniqid());
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testApplication405ReturnsAllowHeader(): void
    {
        $this->app->getRouter()->get('/only-get', fn () => 'get');

        $request = new Request('POST', '/only-get');
        $response = $this->app->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertNotNull($response->getHeader('Allow'));
    }

    public function testApplicationIsolate404From500(): void
    {
        // First, a 404
        $response404 = $this->app->handle(new Request('GET', '/nonexistent-route-'.uniqid()));
        $this->assertSame(404, $response404->getStatusCode());

        // Then a 500 via a route that throws
        $this->app->getRouter()->get('/crash', fn () => throw new RuntimeException('Crash!'));
        $response500 = $this->app->handle(new Request('GET', '/crash'));
        $this->assertSame(500, $response500->getStatusCode());

        // Verify the 404 response is still intact (state isolation)
        $this->assertSame(404, $response404->getStatusCode());
    }
}