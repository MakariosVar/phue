<?php

declare(strict_types=1);

namespace Phue\Tests\Rest;

use Phue\Core\Application;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Prod-level acceptance tests for the RestModule (attribute-driven public REST API).
 *
 * Covers: exposure gating (#[Rest]), hidden-field redaction (index/show/schema)
 * and input rejection (store/update), CRUD lifecycle, read-only entities,
 * auth-gating of non-public entities, custom domain actions (collection/member,
 * public override/inheritance), and the invariant that /api/schii/* is untouched.
 *
 * @package Phue\Tests\Rest
 */
final class RestModuleAcceptanceTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__) . '/Fixtures/RestApp');

        $db = $this->app->getContainer()->get(Connection::class);

        // Drop tables for test isolation (using file-based SQLite)
        $db->execute('DROP TABLE IF EXISTS users');
        $db->execute('DROP TABLE IF EXISTS articles');
        $db->execute('DROP TABLE IF EXISTS products');
        $db->execute('DROP TABLE IF EXISTS secrets');

        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT, auth_key TEXT, roles TEXT)');
        $db->execute('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT)');
        $db->execute('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $db->execute('CREATE TABLE secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, secret_value TEXT)');

        $db->execute("INSERT INTO users (username, password_hash, auth_key, roles) VALUES ('alice', 'hash-a', 'ak-a', '[]')");
        $db->execute("INSERT INTO users (username, password_hash, auth_key, roles) VALUES ('bob', 'hash-b', 'ak-b', '[]')");
        $db->execute("INSERT INTO articles (title, body) VALUES ('Hello', 'World')");
        $db->execute("INSERT INTO products (name) VALUES ('Gizmo')");
        $db->execute("INSERT INTO secrets (name, secret_value) VALUES ('root', 'do-not-tell')");
    }

    private function call(string $method, string $uri, array $body = []): Response
    {
        return $this->app->handle(new Request($method, $uri, [], $body, ['Accept' => 'application/json']));
    }

    private function payload(string $method, string $uri, array $body = []): array
    {
        $decoded = json_decode($this->call($method, $uri, $body)->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function assertJsonError(int $status, string $method, string $uri, array $body = []): array
    {
        $response = $this->call($method, $uri, $body);
        $this->assertSame($status, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);

        return $decoded;
    }

    // ─────────────────────────────── Exposure ───────────────────────────────

    public function testEntityWithRestAttributeIsExposed(): void
    {
        $this->assertSame(200, $this->call('GET', '/api/user')->getStatusCode());
    }

    public function testEntityWithoutRestAttributeIsNotExposed(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/product')->getStatusCode());
    }

    public function testUnknownResourceReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/missing')->getStatusCode());
    }

    // ─────────────────────────────── Schema ───────────────────────────────

    public function testSchemaReturnsEntityMetadata(): void
    {
        $schema = $this->payload('GET', '/api/user/schema');

        $this->assertSame('User', $schema['entity'] ?? null);
        $this->assertArrayHasKey('fields', $schema);
    }

    public function testSchemaForUnexposedEntityReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/product/schema')->getStatusCode());
    }

    public function testSchemaForUnknownEntityReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/nope/schema')->getStatusCode());
    }

    public function testSchemaOmitsHiddenFields(): void
    {
        $schema = $this->payload('GET', '/api/user/schema');
        $names = array_column($schema['fields'] ?? [], 'name');

        $this->assertNotContains('password_hash', $names);
        $this->assertContains('username', $names);
    }

    // ─────────────────────────────── Hidden fields ───────────────────────────────

    public function testListingRedactsHiddenFields(): void
    {
        $list = $this->payload('GET', '/api/user');

        $this->assertNotEmpty($list['data'] ?? []);
        $first = $list['data'][0];
        $this->assertArrayNotHasKey('password_hash', $first);
        $this->assertArrayHasKey('username', $first);
        $this->assertArrayHasKey('id', $first);
    }

    public function testShowRedactsHiddenFields(): void
    {
        $show = $this->payload('GET', '/api/user/1');

        $this->assertArrayNotHasKey('password_hash', $show['data'] ?? []);
        $this->assertSame('alice', $show['data']['username'] ?? null);
    }

    public function testStoreRejectsHiddenFieldInInputWith400(): void
    {
        $err = $this->assertJsonError(400, 'POST', '/api/user', [
            'username' => 'eve',
            'password_hash' => 'attacker-controlled',
        ]);

        $this->assertStringContainsString('password_hash', (string) ($err['error'] ?? ''));
    }

    public function testUpdateRejectsHiddenFieldInInputWith400(): void
    {
        $err = $this->assertJsonError(400, 'PUT', '/api/user/1', ['password_hash' => 'pwned']);

        $this->assertStringContainsString('password_hash', (string) ($err['error'] ?? ''));
    }

    public function testSchemaOfPrivateEntityRequiresAuth(): void
    {
        $this->assertSame(401, $this->call('GET', '/api/secret/schema')->getStatusCode());
    }

    // ─────────────────────────────── CRUD lifecycle ───────────────────────────────

    public function testCreatePersistsRecord(): void
    {
        $create = $this->call('POST', '/api/user', ['username' => 'carol']);
        $this->assertSame(201, $create->getStatusCode());

        $created = json_decode($create->getContent(), true);
        $this->assertSame('carol', $created['data']['username'] ?? null);
        $this->assertArrayNotHasKey('password_hash', $created['data'] ?? []);

        $list = $this->payload('GET', '/api/user');
        $usernames = array_column($list['data'] ?? [], 'username');
        $this->assertContains('carol', $usernames);
    }

    public function testShowReturnsSingleRecord(): void
    {
        $show = $this->payload('GET', '/api/user/1');

        $this->assertSame('alice', $show['data']['username'] ?? null);
    }

    public function testUpdateModifiesRecord(): void
    {
        $update = $this->call('PUT', '/api/user/1', ['username' => 'alicia']);
        $this->assertSame(200, $update->getStatusCode());

        $updated = json_decode($update->getContent(), true);
        $this->assertSame('alicia', $updated['data']['username'] ?? null);
        $this->assertArrayNotHasKey('password_hash', $updated['data'] ?? []);
    }

    public function testDeleteRemovesRecord(): void
    {
        $this->assertSame(200, $this->call('DELETE', '/api/user/1')->getStatusCode());
        $this->assertSame(404, $this->call('GET', '/api/user/1')->getStatusCode());
    }

    public function testShowMissingRecordReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/user/999')->getStatusCode());
    }

    public function testUpdateMissingRecordReturns404(): void
    {
        $this->assertSame(404, $this->call('PUT', '/api/user/999', ['username' => 'x'])->getStatusCode());
    }

    public function testDeleteMissingRecordReturns404(): void
    {
        $this->assertSame(404, $this->call('DELETE', '/api/user/999')->getStatusCode());
    }

    public function testIndexIncludesPaginationMeta(): void
    {
        $list = $this->payload('GET', '/api/user');

        $this->assertArrayHasKey('meta', $list);
        $this->assertArrayHasKey('data', $list);
    }

    public function testIndexSupportsSearch(): void
    {
        $list = $this->payload('GET', '/api/user', ['q' => 'bob']);

        $this->assertNotEmpty($list['data'] ?? []);
        $usernames = array_column($list['data'], 'username');
        $this->assertContains('bob', $usernames);
        $this->assertArrayNotHasKey('password_hash', $list['data'][0]);
    }

    // ─────────────────────────────── Read-only entities ───────────────────────────────

    public function testReadOnlyEntityListIsAllowed(): void
    {
        $this->assertSame(200, $this->call('GET', '/api/article')->getStatusCode());
    }

    public function testReadOnlyEntityShowIsAllowed(): void
    {
        $show = $this->payload('GET', '/api/article/1');

        $this->assertSame('Hello', $show['data']['title'] ?? null);
    }

    public function testReadOnlyEntityRejectsCreate(): void
    {
        $this->assertSame(405, $this->call('POST', '/api/article', ['title' => 'Nope'])->getStatusCode());
    }

    public function testReadOnlyEntityRejectsUpdate(): void
    {
        $this->assertSame(405, $this->call('PUT', '/api/article/1', ['title' => 'Nope'])->getStatusCode());
    }

    public function testReadOnlyEntityRejectsDelete(): void
    {
        $this->assertSame(405, $this->call('DELETE', '/api/article/1')->getStatusCode());
    }

    // ─────────────────────────────── Authentication ───────────────────────────────

    public function testPrivateEntityRequiresAuthentication(): void
    {
        $this->assertJsonError(401, 'GET', '/api/secret');
    }

    public function testPrivateEntityCrudIsNotExposedAnonymously(): void
    {
        $this->assertJsonError(401, 'POST', '/api/secret', ['name' => 'x']);
        $this->assertJsonError(401, 'GET', '/api/secret/1');
        $this->assertJsonError(401, 'PUT', '/api/secret/1', ['name' => 'x']);
        $this->assertJsonError(401, 'DELETE', '/api/secret/1');
    }

    // ─────────────────────────────── Custom actions ───────────────────────────────

    public function testCollectionActionReturnsRedactedRows(): void
    {
        $response = $this->call('GET', '/api/user/active-users');
        $this->assertSame(200, $response->getStatusCode());

        $rows = json_decode($response->getContent(), true);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertArrayNotHasKey('password_hash', $rows[0]);
        $this->assertContains('alice', array_column($rows, 'username'));
    }

    public function testGetCollectionActionWinsOverShowRoute(): void
    {
        $rows = $this->payload('GET', '/api/user/active-users');

        $this->assertNotSame(['error' => 'Resource not found'], $rows);
        $this->assertIsArray($rows);
        $this->assertContains('alice', array_column($rows, 'username'));
    }

    public function testMemberActionReceivesRouteId(): void
    {
        $result = $this->payload('POST', '/api/user/1/activate');

        $this->assertSame(true, $result['ok'] ?? null);
        $this->assertSame(1, $result['userId'] ?? null);
    }

    public function testMemberActionOnMissingRecordReturns404(): void
    {
        $this->assertJsonError(404, 'POST', '/api/user/999/activate');
    }

    public function testMemberActionReturningModelIsRedacted(): void
    {
        $profile = $this->payload('GET', '/api/user/1/profile');

        $this->assertSame(1, $profile['id'] ?? null);
        $this->assertSame('alice', $profile['username'] ?? null);
        $this->assertArrayNotHasKey('password_hash', $profile);
    }

    public function testActionWithPublicOverrideIsAnonymous(): void
    {
        $login = $this->payload('POST', '/api/secret/login');

        $this->assertSame('demo-token', $login['token'] ?? null);
    }

    public function testActionInheritsPrivateVisibility(): void
    {
        $this->assertJsonError(401, 'GET', '/api/secret/state');
    }

    public function testUnknownActionRouteReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/user/does-not-exist')->getStatusCode());
    }

    // ─────────────────────────────── Regression / invariant ───────────────────────────────

    public function testSchiiSchemaStillExposesAllFields(): void
    {
        $schema = $this->payload('GET', '/api/schii/schema/user');

        $names = array_column($schema['fields'] ?? [], 'name');
        $this->assertContains('password_hash', $names);
        $this->assertContains('username', $names);
    }

    public function testSchiiListStillRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->call('GET', '/api/schii/products')->getStatusCode());
    }

    public function testJsonErrorShapeForClientFailures(): void
    {
        $this->assertJsonError(401, 'GET', '/api/secret');
        $this->assertJsonError(404, 'GET', '/api/missing');
    }
}