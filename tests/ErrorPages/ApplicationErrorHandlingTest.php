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
        // debug: false because this file pins what the error pages say in production, and
        // Env::load() below reads the repository's own .env — where APP_DEBUG is true. Left
        // to the environment, the suite asserts against a verbose page on a developer
        // machine and a safe one in CI, which is the ambient-state trap this argument
        // exists to close (see FailClosedDefaultsTest).
        $this->app = new Application($basePath, debug: false);
        $this->isolateApplicationDatabase($this->app);
    }

    public function testApplicationReturnsHtmlErrorPageForUnhandledException(): void
    {
        $this->app->getRouter()->get('/boom', fn () => throw new RuntimeException('Boom!'));

        $request = new Request('GET', '/boom');
        $response = $this->app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $content = $response->getContent();

        // A branded page, not a raw error dump. The assertions that matter are the ones
        // about what must NOT be in it — the message, the class and the source location are
        // exactly what a production error page exists to withhold, and the wording of the
        // page itself is free to change. Asserting one particular sentence instead would
        // make this test fail the day the copy is edited, and pass the day a real leak is
        // introduced elsewhere in the template.
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
        $this->assertStringContainsString('<h1>', $content);
        $this->assertStringNotContainsString('Boom!', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('ApplicationErrorHandlingTest.php', $content);
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