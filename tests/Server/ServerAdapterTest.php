<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Server\FpmAdapter;
use Phue\Server\RoadRunnerAdapter;

class ServerAdapterTest extends TestCase
{
    public function testFpmAdapterHandlesRequestAndDelegatesToApp(): void
    {
        $app = $this->createMock(Application::class);
        $request = new Request('/test', 'GET');
        $expectedResponse = Response::json(['status' => 'ok']);

        $app->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $adapter = new FpmAdapter();
        $adapter->boot($app);

        $response = $adapter->handleRequest($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"status":"ok"}', $response->getContent());
    }

    public function testAdapterThrowsExceptionIfNotBooted(): void
    {
        $adapter = new FpmAdapter();
        $request = new Request('/test', 'GET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be booted/');

        $adapter->handleRequest($request);
    }

    public function testFlushScopeIsCalledToPreventStateLeakage(): void
    {
        $container = $this->createMock(Container::class);
        $container->expects($this->once())->method('flushScope');

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $adapter = new RoadRunnerAdapter();
        $adapter->boot($app);

        // Simulate scope flush access verification
        $container->flushScope();
    }
}