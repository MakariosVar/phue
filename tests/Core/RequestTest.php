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
}