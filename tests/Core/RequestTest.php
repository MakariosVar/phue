<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Request;

#[\PHPUnit\Framework\Attributes\Group('unit')]
final class RequestTest extends TestCase
{
    /**
     * Tests manual instantiation and getter methods of the Request object.
     */
    public function testRequestGetters(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/users',
            queryParams: ['page' => '1'],
            parsedBody: ['name' => 'John'],
            headers: ['Content-Type' => 'application/json']
        );

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/users', $request->getUri());
        $this->assertEquals('1', $request->getQuery('page'));
        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals('application/json', $request->getHeader('content-type'));
    }

    /**
     * Tests HTTP method normalization to uppercase regardless of input casing.
     */
    public function testMethodIsNormalizedToUppercase(): void
    {
        $request = new Request(method: 'get', uri: '/test');

        $this->assertEquals('GET', $request->getMethod());
    }

    /**
     * Tests fallback search order of input() (ParsedBody -> QueryParams -> Default).
     */
    public function testInputFallbackOrder(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/search',
            queryParams: ['q' => 'php', 'filter' => 'query'],
            parsedBody: ['filter' => 'body']
        );

        // ParsedBody should take precedence over QueryParams
        $this->assertEquals('body', $request->input('filter'));
        // QueryParams used if absent from ParsedBody
        $this->assertEquals('php', $request->input('q'));
        // Default used if absent everywhere
        $this->assertEquals('default_val', $request->input('missing', 'default_val'));
    }

    /**
     * Tests that Request::capture() handles CONTENT_TYPE and CONTENT_LENGTH without HTTP_ prefix in $_SERVER.
     */
    public function testCaptureNormalizesContentTypeAndLengthWithoutHttpPrefix(): void
    {
        $backupServer = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/api/data';
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $_SERVER['CONTENT_LENGTH'] = '42';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';

            $request = Request::capture();

            $this->assertEquals('application/json', $request->getHeader('Content-Type'));
            $this->assertEquals('42', $request->getHeader('Content-Length'));
            $this->assertEquals('application/json', $request->getHeader('Accept'));
        } finally {
            $_SERVER = $backupServer;
        }
    }

    /**
     * Tests that capture() defaults to GET on the root path when $_SERVER is empty.
     */
    public function testCaptureDefaultsWhenServerEmpty(): void
    {
        $backupServer = $_SERVER;
        $backupGet = $_GET;

        try {
            $_SERVER = [];
            $_GET = [];

            $request = Request::capture();

            $this->assertEquals('GET', $request->getMethod());
            $this->assertEquals('/', $request->getUri());
            $this->assertEquals([], $request->getQueryParams());
            $this->assertEquals([], $request->getParsedBody());
        } finally {
            $_SERVER = $backupServer;
            $_GET = $backupGet;
        }
    }

    /**
     * Tests that capture() strips the query string from the URI and keeps it in queryParams.
     */
    public function testCaptureStripsQueryStringFromUri(): void
    {
        $backupServer = $_SERVER;
        $backupGet = $_GET;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/users?page=2&sort=desc';
            $_SERVER['HTTP_HOST'] = 'example.com';
            $_GET = ['page' => '2', 'sort' => 'desc'];

            $request = Request::capture();

            $this->assertEquals('/users', $request->getUri());
            $this->assertSame(['page' => '2', 'sort' => 'desc'], $request->getQueryParams());
            $this->assertEquals('example.com', $request->getHeader('host'));
        } finally {
            $_SERVER = $backupServer;
            $_GET = $backupGet;
        }
    }

    /**
     * Tests getQuery() and getHeader() default fallback values.
     */
    public function testGetterDefaultsWhenKeyMissing(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/',
            queryParams: ['page' => '1'],
            headers: ['X-Custom' => 'yes']
        );

        $this->assertEquals('default-page', $request->getQuery('missing', 'default-page'));
        $this->assertNull($request->getQuery('missing'));
        $this->assertEquals('fallback', $request->getHeader('X-Nope', 'fallback'));
        $this->assertNull($request->getHeader('X-Nope'));
        $this->assertEquals('yes', $request->getHeader('x-custom'));
    }

    /**
     * Tests that input() does NOT preserve an explicit null body value: because the
     * implementation uses the null-coalescing operator, a null body value falls
     * through to the query parameter, then to the default. This pins the current
     * documented contract (differs from Laravel-style input() which would return null).
     */
    public function testInputNullBodyValueFallsThroughToQuery(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/',
            parsedBody: ['nullable' => null, 'real' => 'v'],
            queryParams: ['nullable' => 'from-query']
        );

        $this->assertSame('from-query', $request->input('nullable', 'default'));
        $this->assertEquals('v', $request->input('real'));
        $this->assertEquals('default', $request->input('absent', 'default'));
    }

    /**
     * Tests that getServerParams() returns the raw server parameters.
     */
    public function testGetServerParams(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'REQUEST_TIME' => 123456];
        $request = new Request(method: 'GET', uri: '/', serverParams: $server);

        $this->assertSame($server, $request->getServerParams());
    }

    /**
     * Tests that fromPsr7() maps headers, query, body, path and method.
     */
    public function testFromPsr7MapsAllPieces(): void
    {
        $psr7 = new \Nyholm\Psr7\ServerRequest('PATCH', '/posts/42', [
            'X-Multi' => ['a', 'b'],
            'X-Single' => 'one',
        ], 'body', '1.1', ['REMOTE_ADDR' => '10.0.0.1']);

        $psr7 = $psr7
            ->withQueryParams(['page' => '3'])
            ->withParsedBody(['title' => 'Hello']);

        $request = Request::fromPsr7($psr7);

        $this->assertEquals('PATCH', $request->getMethod());
        $this->assertEquals('/posts/42', $request->getUri());
        $this->assertSame(['page' => '3'], $request->getQueryParams());
        $this->assertSame(['title' => 'Hello'], $request->getParsedBody());
        $this->assertEquals('a, b', $request->getHeader('X-Multi'));
        $this->assertEquals('one', $request->getHeader('x-single'));
        $this->assertSame(['REMOTE_ADDR' => '10.0.0.1'], $request->getServerParams());
    }

    /**
     * Tests that fromPsr7() coerces a non-array parsed body to an empty array.
     */
    public function testFromPsr7CoercesNonArrayParsedBody(): void
    {
        $psr7 = (new \Nyholm\Psr7\ServerRequest('POST', '/upload'))
            ->withParsedBody(new \stdClass());

        $request = Request::fromPsr7($psr7);

        $this->assertEquals([], $request->getParsedBody());
    }
}