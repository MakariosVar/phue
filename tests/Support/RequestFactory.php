<?php

namespace Phue\Tests\Support;

use Phue\Core\Request;

/**
 * Shared Request factory.
 */
final class RequestFactory
{
    public static function get(string $uri, array $query = [], array $headers = []): Request
    {
        return new Request('GET', $uri, $query, [], $headers);
    }

    public static function post(string $uri, array $body = [], array $headers = []): Request
    {
        return new Request('POST', $uri, [], $body, $headers);
    }

    public static function json(string $method, string $uri, array $body = [], array $headers = []): Request
    {
        $headers['Accept'] = 'application/json';
        return new Request($method, $uri, [], $body, $headers);
    }
}