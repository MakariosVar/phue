<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Response;

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
}
