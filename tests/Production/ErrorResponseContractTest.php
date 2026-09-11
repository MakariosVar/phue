<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for HTTP error responses produced by the application kernel.
 *
 * Verifies that every terminal error path (400, 404, 405, 500) carries the
 * enterprise security headers, that HSTS is emitted only on secure requests,
 * and that error messages are masked when the application runs with debug mode
 * disabled (production behavior).
 */
class ErrorResponseContractTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/app');
    }

    public function test404CarriesSecurityHeaders(): void
    {
        $response = $this->app->handle(new Request('GET', '/missing-route-xyz'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeader('Referrer-Policy'));
    }

    public function test404EmitsHstsOnlyWhenRequestIsSecure(): void
    {
        $plain = $this->app->handle(new Request('GET', '/missing-route-xyz'));
        $this->assertNull($plain->getHeader('Strict-Transport-Security'));

        $secure = new Request('GET', '/missing-route-xyz', [], [], ['X-Forwarded-Proto' => 'https']);
        $secureResponse = $this->app->handle($secure);

        $this->assertSame(404, $secureResponse->getStatusCode());
        $this->assertStringContainsString('max-age=31536000', (string) $secureResponse->getHeader('Strict-Transport-Security'));
    }

    public function test405CarriesSecurityHeadersAndAllowHeader(): void
    {
        $this->app->getRouter()->post('/only-post', fn (): Response => new Response('Created', 201));

        $response = $this->app->handle(new Request('GET', '/only-post'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('POST', (string) $response->getHeader('Allow'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function test500CarriesSecurityHeaders(): void
    {
        $this->app->getRouter()->get('/boom', function (): never {
            throw new \RuntimeException('Kernel contract test failure');
        });

        $response = $this->app->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test400MessageMaskedInProductionMode(): void
    {
        $app = new Application(__DIR__ . '/_tmp_conftest');
        $app->getRouter()->get('/int/{id}', function (int $id): Response {
            return Response::json(['id' => $id]);
        });

        $response = $app->handle(new Request('GET', '/int/abc'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Bad Request', $body['error']);
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }
}