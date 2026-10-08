<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security\Csrf;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Security\Csrf\CsrfConfig;
use Phue\Core\Security\Csrf\CsrfMiddleware;
use Phue\Core\Security\Csrf\CsrfTokenManager;
use Phue\Core\Security\Csrf\Exceptions\CsrfTokenMismatchException;
use Phue\Core\Security\Csrf\Exceptions\MissingApplicationKeyException;
use Phue\Core\Security\SecurityMiddleware;
use Phue\Core\Routing\Router;
use Phue\Tests\Core\Stubs\CsrfStubController;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF middleware's decision table.
 *
 * Every test drives the middleware directly with an explicit "next" callable so that a
 * rejection is observable as "the handler never ran", not merely as "a response came
 * back". A middleware that swallowed a rejection and returned some other response would
 * satisfy a weaker assertion.
 *
 * @package Phue\Tests\Core\Security\Csrf
 */
#[Group('unit')]
final class CsrfMiddlewareTest extends TestCase
{
    private CsrfTokenManager $manager;

    private CsrfConfig $config;

    private CsrfMiddleware $middleware;

    private bool $handlerRan = false;

    private ?Response $handlerResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $key = 'base64:' . base64_encode(random_bytes(32));
        $this->manager = CsrfTokenManager::fromConfigValue($key);
        $this->config = new CsrfConfig(appKey: $key);
        // A route must exist, and must not be exempt. With an empty router every request
        // resolves to VERDICT_NO_ROUTE and is exempt, which would make the rejection tests
        // pass for entirely the wrong reason.
        $router = new Router();
        $router->post('/x', CsrfStubController::class . '@handle');
        $router->put('/x', CsrfStubController::class . '@handle');
        $router->delete('/x', CsrfStubController::class . '@handle');
        // Router has no patch() shorthand, unlike get/post/put/delete.
        $router->addRoute('PATCH', '/x', CsrfStubController::class . '@handle');

        $this->middleware = new CsrfMiddleware(
            config: $this->config,
            logger: new NullLogger(),
            router: $router,
            security: new SecurityMiddleware(),
        );
    }

    private function next(): callable
    {
        return function (Request $request): Response {
            $this->handlerRan = true;

            return $this->handlerResponse ??= new Response('handler ran', 200);
        };
    }

    private function issue(string $method = 'GET'): string
    {
        return $this->manager->generate();
    }

    // ---------------------------------------------------------------- safe methods

    #[DataProvider('safeMethods')]
    public function testSafeMethodsNeedNoToken(string $method): void
    {
        $response = ($this->middleware)(new Request($method, '/x'), $this->next());

        $this->assertTrue($this->handlerRan);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeMethods(): array
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
            'TRACE' => ['TRACE'],
            // Lower-case input must still be recognised, since Request upper-cases but a
            // middleware should not depend on that having happened.
            'lowercase get' => ['get'],
        ];
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodsWithoutATokenAreRejected(string $method): void
    {
        try {
            ($this->middleware)(
                new Request($method, '/x', [], [], [], [], ['session' => 'ambient']),
                $this->next()
            );
            $this->fail('Expected CsrfTokenMismatchException.');
        } catch (CsrfTokenMismatchException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse($this->handlerRan, 'The handler must never run for a rejected request.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    // ------------------------------------------------------------------- transport

    public function testAValidHeaderTokenIsAccepted(): void
    {
        $request = new Request('POST', '/x', [], [], [
            'X-CSRF-Token' => $this->issue(),
        ], [], ['phue_csrf' => $this->issue()]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    public function testHeaderNameIsMatchedCaseInsensitively(): void
    {
        $request = new Request('POST', '/x', [], [], ['x-csrf-token' => $this->issue()], [], ['phue_csrf' => $this->issue()]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    public function testAValidBodyFieldTokenIsAccepted(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => $this->issue()], [], [], ['phue_csrf' => $this->issue()]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    /**
     * A token in a URL is a token in the Referer header, in the access log and in browser
     * history. It must not be accepted, even though Request::input() would happily find it.
     */
    public function testATokenInTheQueryStringIsNotAValidTransport(): void
    {
        $request = new Request('POST', '/x', ['_csrf' => $this->issue()], [], [], [], ['phue_csrf' => $this->issue()]);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    public function testATokenInTheCookieAloneIsNotAValidTransport(): void
    {
        $request = new Request('POST', '/x', [], [], [], [], ['phue_csrf' => $this->issue()]);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    public function testHeaderTakesPrecedenceOverTheBodyField(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => 'garbage'], [
            'X-CSRF-Token' => $this->issue(),
        ], [], ['phue_csrf' => $this->issue()]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    public function testAGarbageHeaderIsRejectedEvenWithAValidBodyField(): void
    {
        $request = new Request('POST', '/x', [], ['_csrf' => $this->issue()], [
            'X-CSRF-Token' => 'garbage',
        ], [], ['session' => 'ambient']);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    public function testAForeignTokenIsRejected(): void
    {
        $other = CsrfTokenManager::fromConfigValue('base64:' . base64_encode(random_bytes(32)));
        $request = new Request('POST', '/x', [], [], ['X-CSRF-Token' => $other->generate()], [], ['phue_csrf' => $this->issue()]);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    // -------------------------------------------------------------- non-ambient auth

    /**
     * This is the rule that lets the Schii and Rest APIs keep working untouched. A Bearer
     * header is added by the client's own code, so a third-party page cannot cause it to be
     * sent, and demanding a CSRF token on top of it would be security theatre.
     */
    public function testBearerAuthenticatedRequestsSkipVerification(): void
    {
        $request = new Request('POST', '/api/users', [], [], [
            'Authorization' => 'Bearer some-token',
        ]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    public function testBearerSchemeIsMatchedCaseInsensitively(): void
    {
        $request = new Request('POST', '/api/users', [], [], [
            'Authorization' => 'bearer some-token',
        ]);

        ($this->middleware)($request, $this->next());

        $this->assertTrue($this->handlerRan);
    }

    /**
     * "Basic" IS ambient in a browser-managed sense elsewhere, and more importantly it is
     * not in our non-ambient list, so it must be verified rather than waved through.
     */
    public function testAnUnknownAuthorizationSchemeIsStillVerified(): void
    {
        $request = new Request('POST', '/x', [], [], [
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ], [], ['phue_csrf' => $this->issue()]);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    public function testABareBearerWordWithoutCredentialsIsNotTreatedAsNonAmbient(): void
    {
        $request = new Request('POST', '/x', [], [], [
            'Authorization' => 'Bearer',
        ], [], ['phue_csrf' => $this->issue()]);

        $this->expectException(CsrfTokenMismatchException::class);

        ($this->middleware)($request, $this->next());
    }

    // ----------------------------------------------------------------- exempt route

    public function testAnExemptActionSkipsVerification(): void
    {
        $router = new Router();
        $router->post('/webhooks/stripe', CsrfStubController::class . '@exempt');
        $middleware = new CsrfMiddleware(
            $this->config,
            new NullLogger(),
            $router,
            new SecurityMiddleware(),
        );

        $middleware(new Request('POST', '/webhooks/stripe'), $this->next());

        $this->assertTrue($this->handlerRan);
    }

    public function testAnExemptActionDoesNotExemptItsNeighbour(): void
    {
        $router = new Router();
        $router->post('/webhooks/stripe', CsrfStubController::class . '@exempt');
        $router->post('/webhooks/other', CsrfStubController::class . '@handle');
        $middleware = new CsrfMiddleware(
            $this->config,
            new NullLogger(),
            $router,
            new SecurityMiddleware(),
        );

        $this->expectException(CsrfTokenMismatchException::class);

        $middleware(
            new Request('POST', '/webhooks/other', [], [], [], [], ['session' => 'ambient']),
            $this->next()
        );
    }

    /**
     * A request that matches no route is not protected, and that is deliberate. This
     * middleware runs before the router, so if it answered first a POST to a path that does
     * not exist would get 403 instead of 404, and a DELETE against a GET-only path would get
     * 403 instead of 405 with its mandatory Allow header. There is no handler to protect,
     * and answering first would leak which routes exist.
     */
    public function testAnUnmatchedRouteIsLeftToTheRouter(): void
    {
        $response = ($this->middleware)(new Request('POST', '/nowhere'), $this->next());

        $this->assertTrue($this->handlerRan);
        $this->assertSame(200, $response->getStatusCode());
    }

    // --------------------------------------------------------------- anti-oracle

    /**
     * Missing, malformed and wrong-signature must be indistinguishable from outside.
     */
    public function testEveryRejectionPathProducesAnIdenticalException(): void
    {
        $exceptions = [];

        foreach ([
            [],
            ['X-CSRF-Token' => 'nonsense'],
            ['X-CSRF-Token' => 'AAAA.' . str_repeat('B', 43)],
            ['X-CSRF-Token' => CsrfTokenManager::fromConfigValue(
                'base64:' . base64_encode(random_bytes(32))
            )->generate()],
        ] as $headers) {
            try {
                ($this->middleware)(
                    new Request('POST', '/x', [], [], $headers, [], ['phue_csrf' => $this->issue()]),
                    $this->next()
                );
                $this->fail('Expected a rejection.');
            } catch (CsrfTokenMismatchException $e) {
                $exceptions[] = [
                    'message' => $e->getMessage(),
                    'status' => $e->getStatusCode(),
                ];
            }
        }

        $this->assertCount(1, array_unique(array_column($exceptions, 'message')));
        $this->assertCount(1, array_unique(array_column($exceptions, 'status')));
    }

    public function testTheReasonIsAvailableForLoggingButNotInTheClientMessage(): void
    {
        try {
            ($this->middleware)(new Request('POST', '/x', [], [], [], [], ['phue_csrf' => $this->issue()]), $this->next());
            $this->fail('Expected a rejection.');
        } catch (CsrfTokenMismatchException $e) {
            $this->assertNotSame('', $e->getReason());
            $this->assertStringNotContainsString($e->getReason(), $e->getMessage());
        }
    }

    // ------------------------------------------------------------- master switch

    public function testDisabledProtectionDemandsNothing(): void
    {
        $middleware = new CsrfMiddleware(
            new CsrfConfig(enabled: false, appKey: $this->config->appKey),
            new NullLogger(),
            new Router(),
            new SecurityMiddleware(),
        );


        $middleware(new Request('POST', '/x', [], [], [], [], ['phue_csrf' => $this->issue()]), $this->next());

        $this->assertTrue($this->handlerRan);
    }

    // --------------------------------------------------------------- missing key

    public function testAnUnusableKeyFailsTheWriteLoudlyRatherThanAllowingIt(): void
    {
        $router = new Router();
        $router->post('/x', CsrfStubController::class . '@handle');

        $middleware = new CsrfMiddleware(
            new CsrfConfig(appKey: null),
            new NullLogger(),
            $router,
            new SecurityMiddleware(),
        );

        $this->expectException(MissingApplicationKeyException::class);
        $this->expectExceptionMessageMatches('/generate:key/');

        $middleware(new Request('POST', '/x', [], [], [], [], ['session' => 'ambient']), $this->next());
    }

    public function testAnUnusableKeyDoesNotStopReads(): void
    {
        $middleware = new CsrfMiddleware(
            new CsrfConfig(appKey: null),
            new NullLogger(),
            new Router(),
            new SecurityMiddleware(),
        );

        $response = $middleware(new Request('GET', '/x'), $this->next());

        $this->assertTrue($this->handlerRan);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------- token issuance

    public function testHtmlResponsesCarryTheTokenCookie(): void
    {
        $request = new Request('GET', '/page');
        $response = $this->middleware->issueTokenCookie(Response::html('<p>hi</p>'), $request);

        $cookies = $response->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('phue_csrf', $cookies[0]->name);
        $this->assertTrue($this->manager->verify($cookies[0]->value));
    }

    public function testJsonResponsesCarryNoTokenCookie(): void
    {
        $request = new Request('GET', '/api/users');
        $response = $this->middleware->issueTokenCookie(Response::json(['a' => 1]), $request);

        $this->assertSame([], $response->getCookies());
    }

    public function testIssuanceIsIdempotentSoTheTokenDoesNotRotateOnEveryRender(): void
    {
        $request = new Request('GET', '/page');

        $first = $this->middleware->issueTokenCookie(Response::html('a'), $request);
        $second = $this->middleware->issueTokenCookie($first, $request);

        $this->assertCount(1, $second->getCookies());
    }

    public function testTheTokenCookieIsNotHttpOnlyBecauseTheClientMustReadIt(): void
    {
        $response = $this->middleware->issueTokenCookie(Response::html('x'), new Request('GET', '/'));

        $this->assertFalse($response->getCookies()[0]->httpOnly);
    }

    public function testTheTokenCookieIsSameSiteStrictSoCrossSiteRequestsWithholdIt(): void
    {
        $response = $this->middleware->issueTokenCookie(Response::html('x'), new Request('GET', '/'));

        $this->assertSame('Strict', $response->getCookies()[0]->getSameSite());
    }

    public function testTheTokenCookieIsSecureOverHttps(): void
    {
        $request = new Request('GET', '/', [], [], [], ['HTTPS' => 'on']);
        $response = $this->middleware->issueTokenCookie(Response::html('x'), $request);

        $this->assertTrue($response->getCookies()[0]->secure);
    }

    public function testTheTokenCookieIsNotMarkedSecureOnPlainHttpDevelopment(): void
    {
        $request = new Request('GET', '/', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $this->middleware->issueTokenCookie(Response::html('x'), $request);

        $this->assertFalse($response->getCookies()[0]->secure);
    }

    public function testNoTokenCookieIsIssuedWhenTheKeyIsUnusable(): void
    {
        $middleware = new CsrfMiddleware(
            new CsrfConfig(appKey: null),
            new NullLogger(),
            new Router(),
            new SecurityMiddleware(),
        );

        $response = $middleware->issueTokenCookie(Response::html('x'), new Request('GET', '/'));

        $this->assertSame([], $response->getCookies());
    }

    /**
     * The round trip the whole design rests on: whatever the server issues in the cookie
     * must be exactly what it accepts back in the header.
     */
    public function testTheIssuedCookieValueIsAcceptedBackInTheHeader(): void
    {
        $request = new Request('GET', '/page');
        $issued = $this->middleware->issueTokenCookie(Response::html('x'), $request);
        $token = $issued->getCookies()[0]->value;

        $this->handlerRan = false;
        ($this->middleware)(
            new Request('POST', '/x', [], [], ['X-CSRF-Token' => $token]),
            $this->next()
        );

        $this->assertTrue($this->handlerRan);
    }
}
