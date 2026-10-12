<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use Phue\Core\Application;
use Phue\Core\Http\Exceptions\HttpException;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * A registry whose beginRequest() fails the way a real one could.
 *
 * Application::handle() calls ComponentLoaderRegistry::beginRequest() as the
 * first statement inside its try block, before the middleware pipeline runs.
 * An HttpException raised there never reaches ErrorHandlerMiddleware, so this
 * is the only way to observe what the kernel's own catch branch does.
 */
final class PrePipelineFailureRegistry extends \Phue\Core\Frontend\ComponentLoaderRegistry
{
    public function beginRequest(): void
    {
        throw new HttpException('Upstream cache unavailable', 503);
    }
}

/**
 * The error contract, spelled out.
 *
 * The framework has one deliberate rule about exception messages:
 *
 *     HttpException  -> status AND message cross to the client, debug or not.
 *     everything else -> masked in production.
 *
 * That asymmetry is not an oversight. The class exists so application code can
 * say something to a caller, and the class name is where the decision gets
 * made. Three existing tests already pin both halves:
 *
 *   RestErrorProductionTest::testHttpExceptionKeepsItsMessageInProduction
 *   RestErrorProductionTest::testRouteNotFoundStaysGenericInProduction
 *   AdapterErrorContractTest::testSwooleBackstopPreservesHttpExceptionStatusAndMessage
 *
 * So this file does not re-litigate the rule. It guards the two things the
 * rule does not currently cover.
 *
 * 1. There are TWO implementations of it. ErrorHandlerMiddleware runs inside
 *    the pipeline; Application::handle() carries its own copy for exceptions
 *    raised before the pipeline is entered. Nothing asserts they agree, so a
 *    change to one can silently break the other — which is exactly what
 *    happened here once already, before these tests existed.
 *
 * 2. The HTML and JSON paths of the middleware deliberately differ: JSON gets
 *    the message, HTML gets a branded page from ErrorPageRenderer. That
 *    asymmetry is intended, and is pinned below so a future "consistency"
 *    refactor cannot remove it by accident.
 *
 * @package Phue\Tests\Production
 */
class HttpExceptionHandlingContractTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(__DIR__ . '/_tmp_conftest');
        $this->isolateApplicationDatabase($this->app);

        $this->assertFalse(
            $this->app->isDebug(),
            'These tests describe production behaviour; the application under test must not be in debug mode.'
        );
    }

    public function testTheKernelBranchAgreesWithTheMiddlewareOnStatusAndMessage(): void
    {
        $message = 'Upstream cache unavailable';

        // Inside the pipeline: ErrorHandlerMiddleware catches it.
        $this->app->getRouter()->get('/inside', function () use ($message): never {
            throw new HttpException($message, 503);
        });

        $viaMiddleware = $this->app->handle(
            new Request('GET', '/inside', [], [], ['Accept' => 'application/json'])
        );

        // Before the pipeline: the kernel's own catch branch catches it.
        $this->app->getContainer()->bind(
            \Phue\Core\Frontend\ComponentLoaderRegistry::class,
            static fn(): PrePipelineFailureRegistry => new PrePipelineFailureRegistry()
        );

        $viaKernel = $this->app->handle(
            new Request('GET', '/whatever', [], [], ['Accept' => 'application/json'])
        );

        $this->assertSame($viaMiddleware->getStatusCode(), $viaKernel->getStatusCode());
        $this->assertSame(
            json_decode((string) $viaMiddleware->getContent(), true),
            json_decode((string) $viaKernel->getContent(), true),
            'The same HttpException produced different answers depending on whether it was raised '
            . 'before or inside the middleware pipeline. One class, two implementations, no test '
            . 'comparing them.'
        );
    }

    public function testAnHttpExceptionMessageReachesAJsonClientInProduction(): void
    {
        // Pinned in the opposite direction from a leak test: masking this would
        // break RestErrorProductionTest and AdapterErrorContractTest, and would
        // take away the reason HttpException exists as a distinct class.
        $this->app->getRouter()->get('/expired', function (): never {
            throw new HttpException('Token has expired', 401);
        });

        $response = $this->app->handle(
            new Request('GET', '/expired', [], [], ['Accept' => 'application/json'])
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Token has expired'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testAnOrdinaryExceptionMessageStillDoesNotReachAJsonClient(): void
    {
        // The other half of the rule. Without this, "HttpException messages are
        // safe" would drift into "messages are safe".
        $this->app->getRouter()->get('/leaky', function (): never {
            throw new \RuntimeException('db password is hunter2');
        });

        $response = $this->app->handle(
            new Request('GET', '/leaky', [], [], ['Accept' => 'application/json'])
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function testJsonCarriesTheMessageWhileHtmlRendersTheBrandedPage(): void
    {
        // Deliberate, and therefore pinned: the JSON surface is an API surface,
        // the HTML surface is a product surface. Equalising them would either
        // leak application internals into a page or strip the API of the one
        // signal the application chose to send.
        $this->app->getRouter()->get('/dual', function (): never {
            throw new HttpException('Rate limit exceeded, retry in 30s', 429);
        });

        $json = (string) $this->app->handle(
            new Request('GET', '/dual', [], [], ['Accept' => 'application/json'])
        )->getContent();

        $htmlResponse = $this->app->handle(new Request('GET', '/dual'));

        $this->assertStringContainsString('Rate limit exceeded, retry in 30s', $json);
        $this->assertStringNotContainsString(
            'Rate limit exceeded, retry in 30s',
            (string) $htmlResponse->getContent(),
            'The HTML surface must stay a rendered error page, not the raw message.'
        );
        $this->assertStringContainsString('<html', (string) $htmlResponse->getContent());
    }

    public function testXhrCasingIsHonouredOnTheUncaughtExceptionPath(): void
    {
        // A client is allowed to send `x-requested-with: xmlhttprequest`.
        //
        // Covers the middleware, which uses Request::isXmlHttpRequest() and is
        // therefore case-insensitive. It does NOT cover
        // Application::renderExceptionResponse(), which compared the header by
        // hand with === and was case-sensitive until this landed. That method
        // is unreachable while ErrorHandlerMiddleware is the outermost
        // middleware and there is no public API to remove it — a test
        // asserting otherwise would have passed for the wrong reason.
        $this->app->getRouter()->get('/xhr-casing', function (): never {
            throw new \RuntimeException('server side failure');
        });

        $response = $this->app->handle(
            new Request('GET', '/xhr-casing', [], [], ['X-Requested-With' => 'xmlhttprequest'])
        );

        $this->assertSame(500, $response->getStatusCode());

        $this->assertStringStartsWith(
            '{',
            ltrim((string) $response->getContent()),
            'A lowercase x-requested-with header produced an HTML error page instead of the JSON '
            . 'the client asked for.'
        );
    }
}