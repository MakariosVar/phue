<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Response;

#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ResponseTest extends TestCase
{
    /**
     * Tests creation and configuration of JSON responses via static factory.
     */
    public function testJsonResponseCreation(): void
    {
        $response = Response::json(["status" => "ok"], 201);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('{"status":"ok"}', $response->getContent());
        $this->assertEquals(
            "application/json",
            $response->getHeader("content-type"),
        );
    }

    /**
     * Tests creation of HTML responses via static factory.
     */
    public function testHtmlResponseCreation(): void
    {
        $response = Response::html("<h1>Hello World</h1>", 200);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals("<h1>Hello World</h1>", $response->getContent());
        $this->assertEquals(
            "text/html; charset=utf-8",
            $response->getHeader("Content-Type"),
        );
    }

    /**
     * Tests fluent setters for status code, content, and headers.
     */
    public function testFluentSetters(): void
    {
        $response = new Response();
        $response
            ->setStatusCode(404)
            ->setContent("Not Found")
            ->setHeader("X-Custom-Header", "TestValue");

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals("Not Found", $response->getContent());
        $this->assertEquals(
            "TestValue",
            $response->getHeader("x-custom-header"),
        );
    }

    /**
     * Tests case-insensitive header overwriting (deduplication) in setHeader().
     */
    public function testSetHeaderOverwritesExistingCaseInsensitiveHeader(): void
    {
        $response = Response::json(["data" => "test"]);

        // Initial header set by factory is 'Content-Type' => 'application/json'
        $response->setHeader("content-type", "application/problem+json");

        $this->assertEquals(
            "application/problem+json",
            $response->getHeader("Content-Type"),
        );

        // Ensure no duplicate header keys exist in the headers array
        $headers = $response->getHeaders();
        $this->assertCount(1, $headers);
        $this->assertArrayHasKey("content-type", $headers);
    }

    /**
      * Tests that Response::json() catches JSON encoding failures and returns 500 error.
      * Invalid data (e.g. malformed UTF-8 or recursive references) should produce a server error,
      * not a silent empty JSON object that hides the failure from API clients.
      */
    public function testJsonResponseHandlesUnserializableDataFallback(): void
    {
        // Malformed non-UTF8 binary payload causes json_encode to fail even with JSON_THROW_ON_ERROR
        // Response catches the JsonException and returns a 500 error with structured JSON error message
        $invalidUtf8 = ["data" => "\xB1\x31"];

        $response = Response::json($invalidUtf8);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('Malformed JSON payload', $response->getContent());
    }

    public function testJsonResponseHandlesRecursiveData(): void
    {
        $recursive = ['self' => null];
        $recursive['self'] = &$recursive;

        $response = Response::json($recursive);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('Recursion detected', $response->getContent());
    }
}

