<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security;

use Nyholm\Psr7\ServerRequest;
use Phue\Core\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The cookie surface of Request.
 *
 * The constructor gained a parameter for cookies. Anything already constructing a
 * Request positionally or by name must keep working, so that BC is asserted here
 * rather than assumed.
 */
#[Group('unit')]
final class RequestCookiesTest extends TestCase
{
    /**
     * Globals capture() reads. Restored after each test so a leaked $_COOKIE cannot
     * silently authenticate a later test.
     *
     * @var array<string, array<mixed>>
     */
    private array $globals = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->globals = [
            'server' => $_SERVER,
            'get' => $_GET,
            'post' => $_POST,
            'cookie' => $_COOKIE,
        ];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->globals['server'];
        $_GET = $this->globals['get'];
        $_POST = $this->globals['post'];
        $_COOKIE = $this->globals['cookie'];

        parent::tearDown();
    }

    public function testCookieIsReadableByName(): void
    {
        $request = new Request('GET', '/', cookies: ['sid' => 'abc123']);

        $this->assertSame('abc123', $request->getCookie('sid'));
        $this->assertSame(['sid' => 'abc123'], $request->getCookies());
    }

    public function testAbsentCookieFallsBackToTheSuppliedDefault(): void
    {
        $request = new Request('GET', '/', cookies: ['sid' => 'abc']);

        $this->assertNull($request->getCookie('missing'));
        $this->assertSame('fallback', $request->getCookie('missing', 'fallback'));
    }

    /**
     * Cookie names are case-sensitive per RFC 6265. Header names are not, and
     * getHeader() folds case. A cookie lookup that folded case too would let a
     * cookie named "SID" satisfy a check that meant "sid".
     */
    public function testCookieNamesAreCaseSensitiveUnlikeHeaders(): void
    {
        $request = new Request(
            'GET',
            '/',
            headers: ['X-Token' => 'header-value'],
            cookies: ['sid' => 'lower', 'SID' => 'upper'],
        );

        $this->assertSame('lower', $request->getCookie('sid'));
        $this->assertSame('upper', $request->getCookie('SID'));
        $this->assertNull($request->getCookie('SId'));
        $this->assertSame('header-value', $request->getHeader('x-token'), 'Headers stay case-insensitive.');
    }

    /**
     * The cookie parameter sits last on purpose: every existing positional and named
     * construction site must still bind the way it did before.
     */
    public function testCookiesParameterDoesNotDisturbExistingConstructorCalls(): void
    {
        $positional = new Request('post', '/x', ['q' => 1], ['b' => 2], ['H' => 'v'], ['S' => 3]);

        $this->assertSame('POST', $positional->getMethod());
        $this->assertSame('/x', $positional->getUri());
        $this->assertSame(['q' => 1], $positional->getQueryParams());
        $this->assertSame(['b' => 2], $positional->getParsedBody());
        $this->assertSame(['H' => 'v'], $positional->getHeaders());
        $this->assertSame(['S' => 3], $positional->getServerParams());
        $this->assertSame([], $positional->getCookies(), 'Omitting cookies yields an empty bag.');

        $named = new Request(method: 'GET', uri: '/y');

        $this->assertSame('/y', $named->getUri());
        $this->assertSame([], $named->getCookies());
    }

    public function testCaptureReadsCookiesFromTheCookieSuperglobal(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = ['PHPSESSID' => 'session-value', 'theme' => 'dark'];

        $request = Request::capture();

        $this->assertSame('session-value', $request->getCookie('PHPSESSID'));
        $this->assertSame('dark', $request->getCookie('theme'));
    }

    /**
     * "?token[]=x" makes PHP fill $_COOKIE['token'] with an array. A raw bag is
     * therefore not array<string, string>, and passing one straight through would
     * break every consumer that type-hints the contract.
     */
    public function testCaptureDropsNonScalarCookieValues(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = ['good' => 'value', 'arrayish' => ['a', 'b'], 0 => 'numeric-key'];

        $request = Request::capture();

        $this->assertSame(['good' => 'value'], $request->getCookies());
        $this->assertNull($request->getCookie('arrayish'));
        $this->assertArrayNotHasKey(0, $request->getCookies(), 'A numeric key is not a usable cookie name.');
    }

    public function testCaptureCoercesScalarCookieValuesToString(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = ['flag' => true, 'off' => false];

        $request = Request::capture();

        $this->assertSame(['flag' => '1', 'off' => ''], $request->getCookies());
    }

    public function testCaptureLeavesCookiesEmptyWhenTheSuperglobalIs(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];

        $this->assertSame([], Request::capture()->getCookies());
    }

    public function testFromPsr7ReadsCookieParams(): void
    {
        $psr7 = (new ServerRequest('GET', 'https://example.test/x'))
            ->withCookieParams(['sid' => 'psr7-value', 'theme' => 'light']);

        $request = Request::fromPsr7($psr7);

        $this->assertSame('psr7-value', $request->getCookie('sid'));
        $this->assertSame('light', $request->getCookie('theme'));
    }

    public function testFromPsr7YieldsNoCookiesWhenThePsr7CarriesNone(): void
    {
        $request = Request::fromPsr7(new ServerRequest('GET', 'https://example.test/x'));

        $this->assertSame([], $request->getCookies());
    }

    /**
     * input() merges body then query. Cookies must stay out of that merge: a token
     * arriving as a cookie must not satisfy a field or query lookup by accident.
     */
    public function testCookiesDoNotLeakIntoTheInputLookup(): void
    {
        $request = new Request('POST', '/', ['_csrf' => 'from-query'], [], [], [], ['_csrf' => 'from-cookie']);

        $this->assertSame('from-query', $request->input('_csrf'));
        $this->assertSame('from-cookie', $request->getCookie('_csrf'));
    }
}