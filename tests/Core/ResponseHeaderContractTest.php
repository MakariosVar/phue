<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Response;
use Phue\Core\Security\Cookie;
use Phue\Core\Security\SecurityMiddleware;

/**
 * The header contract, pinned independently of how Response stores headers.
 *
 * Why this file exists
 * --------------------
 * Response::setHeader() performs case-insensitive replacement. That lookup was
 * rewritten from a linear scan with strtolower() on every registered key into a
 * hash index, because the kernel applies the security header block twice by
 * design — once in SecurityMiddleware for the normal path, once after the
 * pipeline so an exception-produced response cannot escape without them. Twelve
 * scans of every header, twice per request, to set six headers.
 *
 * The rewrite is only acceptable if it is behaviourally indistinguishable. These
 * tests assert the observable contract rather than the implementation, because
 * the observable contract is what the kernel, the adapters and any application
 * code depend on — and because a security header that quietly goes missing is
 * not the kind of regression a passing suite should be trusted to catch here.
 *
 * @package Phue\Tests\Core
 */
#[Group('unit')]
final class ResponseHeaderContractTest extends TestCase
{
    public function testSetHeaderReturnsSelfForFluentChaining(): void
    {
        $response = new Response();

        $this->assertSame($response, $response->setHeader('A', '1'));
    }

    public function testSetHeaderReplacesAnExistingHeaderOfTheSameName(): void
    {
        $response = new Response();
        $response->setHeader('X-Test', 'first');
        $response->setHeader('X-Test', 'second');

        $this->assertSame(['X-Test' => 'second'], $response->getHeaders());
    }

    public function testSetHeaderReplacesRegardlessOfCasing(): void
    {
        $response = new Response();
        $response->setHeader('X-Test', 'first');
        $response->setHeader('x-test', 'second');

        $this->assertCount(1, $response->getHeaders());
        $this->assertSame('second', $response->getHeader('X-Test'));
    }

    public function testTheSurvivingHeaderKeepsTheCasingOfTheNamePassedLast(): void
    {
        $response = new Response();
        $response->setHeader('X-Original', 'a');
        // Same header, different casing — which is the case that must replace.
        $response->setHeader('x-original', 'b');

        $headers = $response->getHeaders();

        $this->assertCount(1, $headers);
        $this->assertArrayHasKey('x-original', $headers);
        $this->assertArrayNotHasKey('X-Original', $headers);
    }

    /**
     * Differently-named headers are not case variants of each other and must both
     * survive. Pinned because an over-eager index could collapse them.
     */
    public function testDistinctHeadersAreNotCollapsedByTheIndex(): void
    {
        $response = new Response();
        $response->setHeader('X-One', '1');
        $response->setHeader('x-two', '2');

        $this->assertCount(2, $response->getHeaders());
        $this->assertSame('1', $response->getHeader('X-One'));
        $this->assertSame('2', $response->getHeader('x-two'));
    }

    public function testGetHeaderIsCaseInsensitive(): void
    {
        $response = new Response('', 200, ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $response->getHeader('content-type'));
        $this->assertSame('application/json', $response->getHeader('CONTENT-TYPE'));
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public function testGetHeaderReturnsTheDefaultForAnAbsentHeader(): void
    {
        $response = new Response();

        $this->assertNull($response->getHeader('X-Absent'));
        $this->assertSame('fallback', $response->getHeader('X-Absent', 'fallback'));
    }

    /**
     * The regression this index could have introduced: headers handed to the
     * constructor never pass through setHeader(), so a case-variant replacement
     * has to find a key the index was never told about.
     */
    public function testConstructorSuppliedHeadersAreStillReplacedCaseInsensitively(): void
    {
        $response = new Response('', 200, ['X-From-Constructor' => 'first']);
        $response->setHeader('x-from-constructor', 'second');

        $this->assertCount(1, $response->getHeaders());
        $this->assertSame('second', $response->getHeader('X-From-Constructor'));
        $this->assertArrayNotHasKey('X-From-Constructor', $response->getHeaders());
    }

    public function testConstructorSuppliedHeadersSurviveUnrelatedSetHeaderCalls(): void
    {
        $response = new Response('', 200, ['X-Kept' => 'yes']);
        $response->setHeader('X-Other', 'no');

        $this->assertSame('yes', $response->getHeader('X-Kept'));
        $this->assertSame('no', $response->getHeader('X-Other'));
        $this->assertCount(2, $response->getHeaders());
    }

    /**
     * The kernel applies the security block twice. Idempotence is the property
     * that makes the second application harmless, so it is asserted directly
     * rather than left to the wider suite.
     */
    public function testApplyingSecurityHeadersTwiceLeavesOneOfEach(): void
    {
        $security = new SecurityMiddleware([]);
        $request = new \Phue\Core\Request('GET', '/metrics');

        $response = $security->applyHeaders(new Response('ok'), $request);
        $twice = $security->applyHeaders($response, $request);
        $thrice = $security->applyHeaders($twice, $request);

        $headers = $thrice->getHeaders();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame(count($headers), count($thrice->getHeaders()));
        $this->assertSame(1, $this->countHeader($thrice, 'Content-Security-Policy'));
        $this->assertSame(1, $this->countHeader($thrice, 'X-Content-Type-Options'));
    }

    public function testSecurityHeadersAreNotEmittedOnAPlainHttpRequest(): void
    {
        $security = new SecurityMiddleware([]);
        $response = $security->applyHeaders(new Response('ok'), new \Phue\Core\Request('GET', '/x'));

        $this->assertNull($response->getHeader('Strict-Transport-Security'));
    }

    public function testHstsIsEmittedOnASecureRequest(): void
    {
        $security = new SecurityMiddleware([]);
        $request = new \Phue\Core\Request('GET', '/x', [], [], [], ['HTTPS' => 'on']);

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $security->applyHeaders(new Response('ok'), $request)->getHeader('Strict-Transport-Security')
        );
    }

    public function testCookiesAreUnaffectedByHeaderReplacement(): void
    {
        $response = new Response('ok');
        $response->setHeader('X-One', '1');
        $response->withCookie(new Cookie('a', '1'));

        $response->setHeader('X-Two', '2');

        $this->assertCount(1, $response->getCookies());
        $this->assertSame('1', $response->getHeader('X-One'));
    }

    public function testHeadersAndCookiesSurviveConversionToPsr7(): void
    {
        $psr7 = (new Response('ok'))->setHeader('X-Test', 'v')->toPsr7();

        $this->assertSame('v', $psr7->getHeaderLine('X-Test'));
    }

    private function countHeader(Response $response, string $name): int
    {
        return count(array_filter(
            array_keys($response->getHeaders()),
            static fn (string $key): bool => strtolower($key) === strtolower($name)
        ));
    }
}