<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Schii\Attribute\Field;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Server\RoadRunnerAdapter;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[UI(label: 'Test Products', icon: 'box', layout: 'table')]
class TestProduct extends BaseActiveRecord
{
    protected string $table = 'test_products';

    #[Field(type: 'text', label: 'Product Name', searchable: true)]
    public string $name = '';
}

/**
 * Tests for persistent server adapters: Swoole and RoadRunner.
 *
 * @package Phue\Tests\Server
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PersistentServerAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $connection = new Connection(['driver' => 'sqlite']);
        BaseActiveRecord::setConnection($connection);
        $connection->execute("CREATE TABLE IF NOT EXISTS test_products (id INTEGER PRIMARY KEY, name TEXT)");
    }

    public function testModelEndpointSchemaReturnsSchemaData(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('GET', '/schii/schema/products');
        $response = $endpoint->schema($request, 'products');

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('entity', $data);
        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertSame('TestProduct', $data['entity']);
        $this->assertSame('Product Name', $data['fields'][0]['label']);
    }

    public function testModelEndpointSchemaUnknownEntityReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('GET', '/schii/schema/unknown');
        $response = $endpoint->schema($request, 'unknown');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('not found', $response->getContent());
    }

    public function testModelEndpointShowReturnsRowData(): void
    {
        $id = TestProduct::create(['name' => 'Test Product'])->id;

        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('POST', '/schii/show');
        $response = $endpoint->show($request, ['entity' => 'products', 'id' => $id]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Test Product', $data['data']['name']);
    }

    public function testModelEndpointShowNotFoundReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('POST', '/schii/show');
        $response = $endpoint->show($request, ['entity' => 'products', 'id' => 999]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('not found', $response->getContent());
    }

    public function testModelEndpointDeleteRemovesRow(): void
    {
        $product = TestProduct::create(['name' => 'To Delete']);
        $id = $product->id;

        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('POST', '/schii/delete');
        $response = $endpoint->delete($request, ['entity' => 'products', 'id' => $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('deleted', $response->getContent());

        $result = TestProduct::find($id);
        $this->assertNull($result);
    }

    public function testModelEndpointDeleteUnknownEntityReturns404(): void
    {
        $registry = new SchemaRegistry();
        $endpoint = new ModelEndpoint($registry);
        $endpoint->registerEntity('products', TestProduct::class);
        $request = new Request('POST', '/schii/delete');
        $response = $endpoint->delete($request, ['entity' => 'unknown', 'id' => 1]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('not found', $response->getContent());
    }

    // Note: SwooleAdapter::transformSwooleRequest() has a source-level bug — it calls
    // new Request(..., bodyParams: ..., ...) but Request::__construct uses parsedBody.
    // This test cannot be written until src/Server/SwooleAdapter.php line 106 is fixed
    // to pass parsedBody instead of bodyParams.

    public function testRoadRunnerRunLoopsUntilWorkerExhausted(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects($this->exactly(2))->method('handle')
            ->willReturn(new Response('pong', 200));

        $worker = new FakePsr7Worker([
            new ServerRequest('GET', '/a'),
            new ServerRequest('GET', '/b'),
        ]);
        $adapter = new RoadRunnerAdapter($worker);
        $adapter->boot($app);
        $adapter->run();

        $responded = $worker->getResponded();
        $this->assertCount(2, $responded);
        $this->assertSame(200, $responded[0]->getStatusCode());
        $this->assertSame('pong', (string) $responded[0]->getBody());
    }

    public function testRoadRunnerRunResponds500OnException(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('handle')
            ->willThrowException(new \RuntimeException('kaboom'));

        $worker = new FakePsr7Worker([new ServerRequest('GET', '/boom')]);
        $adapter = new RoadRunnerAdapter($worker);
        $adapter->boot($app);
        $adapter->run();

        $responded = $worker->getResponded();
        $this->assertCount(1, $responded);
        $this->assertSame(500, $responded[0]->getStatusCode());
        $this->assertStringContainsString('kaboom', (string) $responded[0]->getBody());
    }

    public function testRoadRunnerRunWithEmptyWorkerDoesNothing(): void
    {
        $app = $this->createMock(Application::class);

        $worker = new FakePsr7Worker([]);
        $adapter = new RoadRunnerAdapter($worker);
        $adapter->boot($app);
        $adapter->run();

        $this->assertCount(0, $worker->getResponded());
    }

    public function testRoadRunnerRunWithNoWorkerIsNoOp(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects($this->never())->method('handle');

        $adapter = new RoadRunnerAdapter();
        $adapter->boot($app);
        $adapter->run();
    }
}

/**
 * Fake PSR-7 worker for testing RoadRunnerAdapter.
 */
final class FakePsr7Worker implements PSR7WorkerInterface
{
    /** @var ServerRequestInterface[] */
    private array $requests;

    /** @var Psr7Response[] */
    public array $responded = [];

    public function __construct(array $requests)
    {
        $this->requests = $requests;
    }

    public function waitRequest(): ?ServerRequestInterface
    {
        return array_shift($this->requests) ?? null;
    }

    public function respond(ResponseInterface $response): void
    {
        $this->responded[] = $response;
    }

    /** @return ResponseInterface[] */
    public function getResponded(): array
    {
        return $this->responded;
    }

    public function getWorker(): WorkerInterface
    {
        return new class implements WorkerInterface {
            public function waitPayload(): ?\Spiral\RoadRunner\Payload
            {
                return null;
            }
            public function respond(\Spiral\RoadRunner\Payload $payload): void
            {
            }
            public function error(string $error): void
            {
            }
            public function stop(): void
            {
            }
        };
    }
}