<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security;

use Nyholm\Psr7\Response as Psr7Response;
use Phue\Core\Response;
use Phue\Core\Security\Cookie;
use Phue\Tests\Support\CgiResponseProbe;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The cookie surface of Response.
 *
 * $headers is a flat array<string, string> that setHeader() replaces case-insensitively,
 * so cookies were deliberately kept out of it. These tests pin both halves of that
 * decision: cookies stay out of getHeaders(), and they reach the client as separate
 * Set-Cookie lines anyway.
 */
#[Group('unit')]
final class ResponseCookiesTest extends TestCase
{
    public function testCookiesAreExposedSeparatelyFromHeaders(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('sid', 'abc'));

        $this->assertCount(1, $response->getCookies());
        $this->assertSame([], $response->getHeaders());
        $this->assertNull($response->getHeader('Set-Cookie'));
    }

    public function testMultipleDistinctCookiesAreAllRetainedInOrder(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('a', '1'));
        $response->withCookie(Cookie::create('b', '2'));
        $response->withCookie(Cookie::create('c', '3'));

        $names = array_map(static fn(Cookie $cookie): string => $cookie->name, $response->getCookies());

        $this->assertSame(['a', 'b', 'c'], $names);
    }

    /**
     * A middleware can legitimately run twice over one response, the way
     * SecurityMiddleware::applyHeaders() is re-applied on the exception path. Without
     * identity-based replacement that would stack duplicate Set-Cookie lines and the
     * browser would keep whichever it felt like.
     */
    public function testRequeuingTheSameCookieIdentityReplacesItInPlace(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('sid', 'first'));
        $response->withCookie(Cookie::create('other', 'keep'));
        $response->withCookie(Cookie::create('sid', 'second'));

        $cookies = $response->getCookies();

        $this->assertCount(2, $cookies);
        $this->assertSame('sid', $cookies[0]->name);
        $this->assertSame('second', $cookies[0]->value);
        $this->assertSame('other', $cookies[1]->name);
    }

    public function testSameNameWithDifferentPathIsADifferentCookieAndBothSurvive(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('sid', 'root', path: '/'));
        $response->withCookie(Cookie::create('sid', 'api', path: '/api'));

        $this->assertCount(2, $response->getCookies());
    }

    public function testSameNameWithDifferentDomainIsADifferentCookieAndBothSurvive(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('sid', 'a', domain: 'example.com'));
        $response->withCookie(Cookie::create('sid', 'b', domain: 'api.example.com'));

        $this->assertCount(2, $response->getCookies());
    }

    /**
     * PSR-7 is natively multi-value, so this is the cheap half of the proof: both
     * cookies must be present as distinct header values, not one merged string.
     */
    public function testPsr7ConversionEmitsOneSetCookieValuePerCookie(): void
    {
        $response = new Response('ok');
        $response->withCookie(Cookie::create('first', 'one'));
        $response->withCookie(Cookie::create('second', 'two'));

        $psr7 = $response->toPsr7();

        $this->assertInstanceOf(Psr7Response::class, $psr7);
        $this->assertSame(
            ['first=one; Path=/; SameSite=Lax', 'second=two; Path=/; SameSite=Lax'],
            $psr7->getHeader('Set-Cookie')
        );
    }

    public function testPsr7ConversionPreservesRegularHeadersAlongsideCookies(): void
    {
        $response = Response::json(['a' => 1]);
        $response->withCookie(Cookie::create('sid', 'abc'));

        $psr7 = $response->toPsr7();

        $this->assertSame('application/json', $psr7->getHeaderLine('Content-Type'));
        $this->assertSame(['sid=abc; Path=/; SameSite=Lax'], $psr7->getHeader('Set-Cookie'));
    }

    public function testResponseWithNoCookiesConvertsWithoutSetCookie(): void
    {
        $this->assertSame([], (new Response('ok'))->toPsr7()->getHeader('Set-Cookie'));
    }

    /**
     * The real regression guard. PHP's header() replaces by header NAME unless replace
     * is false, so send() emitting cookies with the default would deliver only the last
     * one while still looking like it worked. Only a real CGI run can observe this, which
     * is why this test shells out to php-cgi instead of asserting on the source.
     */
    #[Group('integration')]
    public function testSendEmitsEveryCookieAsItsOwnHeaderLine(): void
    {
        $lines = CgiResponseProbe::setCookieLines(
            CgiResponseProbe::run(dirname(__DIR__, 3))
        );

        $this->assertCount(2, $lines, 'Both Set-Cookie headers must reach the client.');
        $this->assertSame('first=one; Path=/; SameSite=Lax', $lines[0]);
        $this->assertSame('second=two; Path=/; Secure; HttpOnly; SameSite=Lax', $lines[1]);
    }

    public function testConstructorAcceptsCookiesUpFront(): void
    {
        $response = new Response('ok', 200, [], [Cookie::create('sid', 'abc')]);

        $this->assertCount(1, $response->getCookies());
    }
}