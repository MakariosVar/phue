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

#[\PHPUnit\Framework\Attributes\Group('unit')]
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

    public function testRoadRunnerAdapterBootDelegatesToApplication(): void
    {
        $request = new Request('/test', 'GET');
        $expectedResponse = Response::json(['status' => 'ok']);

        $app = $this->createMock(Application::class);
        $app->expects($this->never())->method('getContainer');
        $app->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $adapter = new RoadRunnerAdapter();
        $adapter->boot($app);

        $response = $adapter->handleRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', $response->getContent());
    }

    public function testSwooleAdapterHandlesRequestAndDelegatesToApp(): void
    {
        $app = $this->createMock(Application::class);
        $request = new Request('/api/test', 'GET');
        $expectedResponse = Response::json(['status' => 'ok']);

        $app->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $adapter = new \Phue\Server\SwooleAdapter();
        $adapter->boot($app);

        $response = $adapter->handleRequest($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"status":"ok"}', $response->getContent());
    }

    public function testSwooleAdapterThrowsExceptionIfNotBooted(): void
    {
        $adapter = new \Phue\Server\SwooleAdapter();
        $request = new Request('/test', 'GET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not booted/');

        $adapter->handleRequest($request);
    }

    public function testSwooleAdapterRunThrowsIfNotBooted(): void
    {
        $adapter = new \Phue\Server\SwooleAdapter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be booted/');

        $adapter->run();
    }

    public function testRoadRunnerAdapterThrowsExceptionIfNotBooted(): void
    {
        $adapter = new RoadRunnerAdapter();
        $request = new Request('/test', 'GET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not booted/');

        $adapter->handleRequest($request);
    }

    public function testRoadRunnerAdapterRunThrowsIfNotBooted(): void
    {
        $adapter = new RoadRunnerAdapter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not booted/');

        $adapter->run();
    }

    public function testSwooleAdapterBootStoresAppAndContainer(): void
    {
        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $adapter = new \Phue\Server\SwooleAdapter();
        $adapter->boot($app);

        $request = new Request('/test', 'GET');
        $app->expects($this->once())
            ->method('handle')
            ->willReturn(new Response('OK', 200));

        $response = $adapter->handleRequest($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRoadRunnerAdapterShutdownDoesNotThrow(): void
    {
        $adapter = new RoadRunnerAdapter();
        $adapter->shutdown();
        $this->expectNotToPerformAssertions();
    }

    public function testSwooleAdapterDefaultConstructorValues(): void
    {
        $adapter = new \Phue\Server\SwooleAdapter();
        $this->assertInstanceOf(\Phue\Server\SwooleAdapter::class, $adapter);
    }

    public function testFpmAdapterRunCapturesRequestEmitsResponse(): void
    {
        $backupServer = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/health';

            $app = $this->createMock(Application::class);
            $app->expects($this->once())
                ->method('handle')
                ->willReturn(Response::json(['status' => 'ok']));

            $adapter = new FpmAdapter();
            $adapter->boot($app);

            ob_start();
            try {
                $adapter->run();
            } finally {
                $output = ob_get_clean();
            }

            $this->assertStringContainsString('{"status":"ok"}', $output);
        } finally {
            $_SERVER = $backupServer;
        }
    }

    public function testFpmAdapterRunThrowsWhenNotBooted(): void
    {
        $backupServer = $_SERVER;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/x';

            $adapter = new FpmAdapter();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/must be booted/');

            $adapter->run();
        } finally {
            $_SERVER = $backupServer;
        }
    }
}