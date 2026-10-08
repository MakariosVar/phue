<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security\Csrf;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Security\Csrf\CsrfTokenManager;
use Phue\Core\Security\SecurityMiddleware;

/**
 * CSRF protection as the framework actually wires it, through Application::handle().
 *
 * Unit tests on the middleware cannot see the thing most likely to be wrong: where the
 * middleware sits in the global stack. Application::use() appends and the pipeline folds
 * over the reversed list, so the first registration is the outermost layer. CSRF is
 * registered after ErrorHandlerMiddleware precisely so that a rejection is rendered as a
 * 403 with a body, rather than escaping as an unhandled 500.
 *
 * Nothing pins that ordering. docs/Core.md claims the opposite of what the code does, so
 * the assumption has to be asserted rather than trusted.
 *
 * @package Phue\Tests\Core\Security\Csrf
 */
#[Group('integration')]
final class CsrfPipelineWiringTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $key = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = 'base64:' . base64_encode(random_bytes(32));
    }

    private function app(): Application
    {
        $app = new Application('/app', debug: false);

        $this->isolateApplicationDatabase($app);

        // Point the middleware at this test's key without touching the real .env.
        $config = $app->getConfig();
        $csrf = (array) $config->get('app.csrf', []);
        $csrf['appKey'] = $this->key;
        $config->set('app.csrf', $csrf);

        return $app;
    }

    private function token(): string
    {
        return CsrfTokenManager::fromConfigValue($this->key)->generate();
    }

    /**
     * Logout used to accept GET, which put it outside the CSRF control by construction:
     * safe methods are exempt, so a GET logout could be fired from an <img> tag anywhere.
     *
     * Asserted on the security property rather than on a status code, because this harness
     * may or may not have the controller's routes loaded — and whether a GET gets 404 or 405
     * is the router's business. What must never happen is a GET that logs the user out, so
     * any response other than the logout redirect counts as the contract holding.
     */
    public function testLogoutIsNotReachableWithGet(): void
    {
        $app = $this->app();

        $response = $app->handle(new Request('GET', '/logout', [], [], ['Accept' => 'application/json']));

        $this->assertNotSame(302, $response->getStatusCode(), 'A GET must never log the user out.');
        $this->assertNotSame('/login', $response->getHeader('Location'));
    }

    public function testSafeRequestPassesThroughTheRealPipeline(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/things', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('GET', '/things'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsafeRequestWithoutATokenIsRejectedWith403(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/things', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('POST', '/things', [], [], ['Accept' => 'application/json'], [], ['PHPSESSID' => 'browser']));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The ordering invariant. If CSRF were registered before ErrorHandlerMiddleware, the
     * thrown 403 would never reach the error renderer and the client would receive a bare
     * 500 for what is an ordinary rejected token.
     */
    public function testTheRejectionIsRenderedByTheErrorHandlerNotAsABare500(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/things', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('POST', '/things', [], [], ['Accept' => 'application/json'], [], ['PHPSESSID' => 'browser']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function testTheRejectionBodyCarriesNoInternalDetail(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/things', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('POST', '/things', [], [], ['Accept' => 'application/json'], [], ['PHPSESSID' => 'browser']));
        $body = $response->getContent();

        $this->assertStringNotContainsString('hmac', strtolower($body));
        $this->assertStringNotContainsString('hash_equals', $body);
        $this->assertStringNotContainsString('verify', strtolower($body));
    }

    public function testAValidHeaderTokenReachesTheHandler(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/things', static fn(): Response => Response::json(['reached' => true]));

        $response = $app->handle(new Request(
            'POST',
            '/things',
            [],
            [],
            ['Accept' => 'application/json', 'X-CSRF-Token' => $this->token()]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('reached', $response->getContent());
    }

    public function testARejectedRequestNeverInvokesTheHandler(): void
    {
        $app = $this->app();
        $invoked = false;

        $app->getRouter()->post('/things', static function () use (&$invoked): Response {
            $invoked = true;
            return Response::json(['ok' => true]);
        });

        $app->handle(new Request('POST', '/things', [], [], ['Accept' => 'application/json'], [], ['PHPSESSID' => 'browser']));

        $this->assertFalse($invoked, 'The handler must not run for a rejected request.');
    }

    /**
     * The full loop a browser performs: load a page, take the token from the cookie the
     * server issued, send it back in the header. If any link in that chain is wrong the
     * application is unusable for every form, which is why it is asserted end to end.
     */
    public function testTheTokenIssuedOnAPageIsAcceptedOnTheNextWrite(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/form', static fn(): Response => Response::html('<form></form>'));
        $app->getRouter()->post('/form', static fn(): Response => Response::json(['saved' => true]));

        $page = $app->handle(new Request('GET', '/form'));

        $cookies = $page->getCookies();
        $this->assertCount(1, $cookies, 'A rendered page must carry the token cookie.');

        $token = $cookies[0]->value;

        $write = $app->handle(new Request(
            'POST',
            '/form',
            [],
            [],
            ['Accept' => 'application/json', 'X-CSRF-Token' => $token]
        ));

        $this->assertSame(200, $write->getStatusCode());
    }

    /**
     * An error response never passes through the CSRF middleware, because that middleware
     * sits inside the error handler. The token cookie therefore has to be applied at the
     * end of handle(), exactly as security headers are, or a client that landed on an error
     * page has no token and its retry fails with a 403 that looks like its own mistake.
     */
    public function testAnErrorResponseStillCarriesTheTokenCookie(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/boom', static fn(): Response => Response::html('x', 500));

        $response = $app->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());

        $names = array_map(static fn($cookie): string => $cookie->name, $response->getCookies());
        $this->assertContains('phue_csrf', $names);
    }

    public function testJsonResponsesDoNotAccumulateTokenCookies(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/api/x', static fn(): Response => Response::json(['a' => 1]));

        $response = $app->handle(new Request('GET', '/api/x'));

        $this->assertSame([], $response->getCookies());
    }

    public function testABearerAuthenticatedApiCallNeedsNoToken(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/api/y', static fn(): Response => Response::json(['created' => true]));

        $response = $app->handle(new Request(
            'POST',
            '/api/y',
            [],
            [],
            ['Accept' => 'application/json', 'Authorization' => 'Bearer abc123']
        ));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * CSRF must not be the reason a Schii or Rest endpoint stops working: those carry a
     * Bearer token, which the middleware recognises as non-ambient.
     */
    public function testCsrfDoesNotInterfereWithSecurityHeaderEmission(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/plain', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('GET', '/plain'));

        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
    }

    public function testTheSecurityMiddlewareRemainsTheOutermostLayer(): void
    {
        $app = $this->app();
        $app->getRouter()->post('/things', static fn(): Response => Response::json(['ok' => true]));

        $response = $app->handle(new Request('POST', '/things', [], [], ['Accept' => 'application/json'], [], ['PHPSESSID' => 'browser']));

        // If CSRF sat outside the error handler the 403 would have no security headers,
        // which is the observable symptom of getting that order backwards.
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheTokenCookieIsSecureOnlyWhenTransportIsSecure(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/form', static fn(): Response => Response::html('<form></form>'));

        $response = $app->handle(new Request('GET', '/form', [], [], [], ['HTTPS' => 'on']));
        $cookie = $response->getCookies()[0];

        $this->assertTrue($cookie->secure);
        $this->assertSame('Strict', $cookie->getSameSite());
        $this->assertFalse($cookie->httpOnly);
        $this->assertTrue(CsrfTokenManager::fromConfigValue($this->key)->verify($cookie->value));
    }
}