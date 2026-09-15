<?php

declare(strict_types=1);

namespace Phue\Tests\Rest;

use Phue\Core\Application;
use Phue\Core\Database\Connection;
use Phue\Core\ErrorPages\ErrorHandlerMiddleware;
use Phue\Core\ErrorPages\ErrorPageRenderer;
use Phue\Core\Request;
use Phue\Core\Response;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies HTTP error responses in PRODUCTION mode (app.debug = false).
 *
 * Contract under test:
 * - HttpExceptions are framework-authored client-facing errors: status + message
 *   stay verbatim in all environments (no generic-ification, no leak).
 * - Internal exceptions (TypeError, RuntimeException, PDO / etc.) must never leak
 *   internals: status becomes 500 and the body is the generic 'Internal Server Error'
 *   without class/file/line/trace keys or underlying messages.
 *
 * @package Phue\Tests\Rest
 */
final class RestErrorProductionTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application(dirname(__DIR__) . '/Fixtures/RestApp');

        // Force the error branch into production configuration regardless of fixture config.
        $app->getContainer()->singleton(
            ErrorHandlerMiddleware::class,
            function ($container): ErrorHandlerMiddleware {
                return new ErrorHandlerMiddleware(
                    renderer: $container->get(ErrorPageRenderer::class),
                    logger: $container->get(LoggerInterface::class),
                    debug: false
                );
            }
        );

        $this->app = $app;

        $db = $app->getContainer()->get(Connection::class);

        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT, auth_key TEXT, roles TEXT)');
        $db->execute('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT)');
        $db->execute('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $db->execute('CREATE TABLE secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, secret_value TEXT)');

        $db->execute("INSERT INTO users (username, password_hash, auth_key, roles) VALUES ('alice', 'hash-a', 'ak-a', '[]')");
        $db->execute("INSERT INTO articles (title, body) VALUES ('Hello', 'World')");
        $db->execute("INSERT INTO secrets (name, secret_value) VALUES ('root', 'do-not-tell')");
    }

    private function call(string $method, string $uri, array $body = []): Response
    {
        return $this->app->handle(new Request($method, $uri, [], $body, ['Accept' => 'application/json']));
    }

    private function json(string $method, string $uri, array $body = []): array
    {
        $decoded = json_decode($this->call($method, $uri, $body)->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testHttpExceptionKeepsItsMessageInProduction(): void
    {
        $response = $this->call('GET', '/api/secret');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized access.', $this->json('GET', '/api/secret')['error'] ?? null);
    }

    public function testHiddenFieldRejectionKeepsFieldNameInProduction(): void
    {
        $err = $this->json('POST', '/api/user', [
            'username' => 'eve',
            'password_hash' => 'attacker-controlled',
        ]);

        $this->assertSame(400, $this->call('POST', '/api/user', ['username' => 'eve', 'password_hash' => 'x'])->getStatusCode());
        $this->assertStringContainsString('password_hash', (string) ($err['error'] ?? ''));
    }

    public function testRouteNotFoundStaysGenericInProduction(): void
    {
        $response = $this->call('GET', '/api/nope');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $this->json('GET', '/api/nope')['error'] ?? null);
    }

    public function testInternalExceptionLeaksNothingInProduction(): void
    {
        $response = $this->call('GET', '/api/secret/explode');

        $this->assertSame(500, $response->getStatusCode());

        $body = $this->json('GET', '/api/secret/explode');
        $this->assertSame('Internal Server Error', $body['error'] ?? null);

        $rendered = $response->getContent();
        $this->assertStringNotContainsString('boom', $rendered);
        $this->assertStringNotContainsString('RuntimeException', $rendered);
        $this->assertStringNotContainsString('"class":', $rendered);
        $this->assertStringNotContainsString('"file":', $rendered);
        $this->assertStringNotContainsString('"line":', $rendered);
    }

    public function testUnknownActionIsStillNotFoundInProduction(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/user/does-not-exist')->getStatusCode());
    }
}