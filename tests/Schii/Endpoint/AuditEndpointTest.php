<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Request;
use Phue\Modules\Schii\Audit\SchiiAudit;
use Phue\Modules\Schii\Endpoint\AuditEndpoint;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * AuditEndpoint contract for the Schii audit panel:
 * filtered log retrieval, limit clamping and per-entity views.
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AuditEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The audit store is a static in-memory buffer: reset it to keep tests isolated.
        $prop = new ReflectionProperty(SchiiAudit::class, 'logs');
        $prop->setValue(null, []);
    }

    private function decode(\Phue\Core\Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    public function testIndexReturnsEmptyLogsWhenNothingLogged(): void
    {
        $request = new Request('GET', '/api/schii/audit');
        $response = (new AuditEndpoint())->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['logs']);
    }

    public function testIndexLogsAreNewestFirst(): void
    {
        SchiiAudit::log('profiles', 'POST', 'admin');
        usleep(1000);
        SchiiAudit::log('profiles', 'DELETE', 'admin');

        $request = new Request('GET', '/api/schii/audit');
        $logs = $this->decode((new AuditEndpoint())->index($request))['logs'];

        $this->assertCount(2, $logs);
        $this->assertSame('DELETE', $logs[0]['verb'], 'Newest log entry must come first.');
        $this->assertSame('POST', $logs[1]['verb']);
        $this->assertSame('admin', $logs[0]['user']);
        $this->assertSame('profiles', $logs[0]['entity']);
        $this->assertIsInt($logs[0]['timestamp']);
    }

    public function testIndexUppercasesLoggedVerbs(): void
    {
        SchiiAudit::log('users', 'get', 'alice');

        $logs = $this->decode((new AuditEndpoint())->index(
            new Request('GET', '/api/schii/audit')
        ))['logs'];

        $this->assertSame('GET', $logs[0]['verb']);
    }

    public function testIndexFiltersByEntityVerbAndUser(): void
    {
        SchiiAudit::log('profiles', 'POST', 'alice');
        SchiiAudit::log('profiles', 'DELETE', 'bob');
        SchiiAudit::log('users', 'POST', 'alice');

        $request = new Request('GET', '/api/schii/audit', [
            'entity' => 'profiles',
            'verb' => 'POST',
            'user' => 'alice',
        ]);
        $logs = $this->decode((new AuditEndpoint())->index($request))['logs'];

        $this->assertCount(1, $logs);
        $this->assertSame('profiles', $logs[0]['entity']);
        $this->assertSame('POST', $logs[0]['verb']);
    }

    public function testIndexClampsLimitTo200(): void
    {
        for ($i = 0; $i < 210; $i++) {
            SchiiAudit::log('profiles', 'GET', "u{$i}");
        }

        $request = new Request('GET', '/api/schii/audit', ['limit' => '999']);
        $data = $this->decode((new AuditEndpoint())->index($request));
        $this->assertSame(200, $data['count']);
    }

    public function testIndexClampsNegativeLimitToZero(): void
    {
        SchiiAudit::log('profiles', 'GET', 'alice');

        $request = new Request('GET', '/api/schii/audit', ['limit' => '-5']);
        $data = $this->decode((new AuditEndpoint())->index($request));
        $this->assertSame(0, $data['count']);
    }

    public function testForEntityFiltersOnlyThatEntity(): void
    {
        SchiiAudit::log('profiles', 'POST', 'alice');
        SchiiAudit::log('users', 'DELETE', 'alice');

        $request = new Request('GET', '/api/schii/audit/users');
        $response = (new AuditEndpoint())->forEntity($request, ['entity' => 'users']);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame('users', $data['entity']);
        $this->assertCount(1, $data['logs']);
        $this->assertSame('users', $data['logs'][0]['entity']);
    }

    public function testPayloadObjectIsPreservedInLogs(): void
    {
        SchiiAudit::log('profiles', 'POST', 'admin', ['id' => 42, 'diff' => ['legacy' => true]]);

        $logs = $this->decode((new AuditEndpoint())->index(
            new Request('GET', '/api/schii/audit')
        ))['logs'];

        $this->assertSame(['id' => 42, 'diff' => ['legacy' => true]], $logs[0]['payload']);
    }

    public function testAuditLogTrimsWhenExceedingMaxLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            SchiiAudit::log('profiles', 'GET', "u{$i}", null, 10);
        }

        $logs = SchiiAudit::getLogs('profiles', limit: 50);
        $this->assertCount(10, $logs, 'Audit log entries in memory must be trimmed to maxLimit');
        // Latest log should be u19
        $this->assertSame('u19', $logs[0]['user']);
    }
}