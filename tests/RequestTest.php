<?php

namespace Phue\Tests;

use PHPUnit\Framework\TestCase;
use Phue\Core\Request;

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
}