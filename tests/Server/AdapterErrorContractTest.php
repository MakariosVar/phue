<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\ErrorPages\ErrorHandlerMiddleware;
use Phue\Core\ErrorPages\ErrorPageRenderer;
use Phue\Core\Http\Exceptions\HttpException;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Security\SecurityMiddleware;
use Phue\Modules\Server\RoadRunnerAdapter;
use Phue\Modules\Server\SwooleAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Throwable;

/**
 * Error contract for the PERSISTENT server adapters (Swoole, RoadRunner).
 *
 * Application::handle() owns an error boundary: it logs, masks in production and
 * applies security headers. The adapters sit OUTSIDE that boundary, so anything
 * they catch is, by definition, the exception the kernel could not handle — and
 * is very often triggered by malformed client input or a broken singleton.
 *
 * Contract under test:
 * - Internal exception detail never reaches the client when debug is off.
 * - HttpException keeps its status + message (framework-authored, client-facing).
 * - Every swallowed failure is logged (a long-lived worker that silently drops
 *   an exception is worse than one that crashes).
 * - The response carries the same security headers as a kernel-produced error.
 * - The worker always emits a well-formed response and keeps serving.
 *
 * @package Phue\Tests\Server
 */
#[Group('unit')]
final class AdapterErrorContractTest extends TestCase
{
    // A message shaped like a real leak: DSN credentials, absolute path, SQL fragment.
    private const LEAKY_MESSAGE = 'SQLSTATE[42S02]: Base table or view not found '
        . '[SELECT * FROM users WHERE email = ?] in /var/www/app/src/Core/Database/Query.php '
        . '(mysql:host=127.0.0.1;dbname=phue;pwd=s3cr3t)';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    // ---------------------------------------------------------------------
    // Swoole: the backstop that the request closure delegates to
    // ---------------------------------------------------------------------

    public function testSwooleBackstopMasksInternalExceptionDetailInProduction(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report'),
            debug: false
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
        $this->assertStringNotContainsString('/var/www/app', $response->getContent());
        $this->assertStringNotContainsString('SELECT * FROM users', $response->getContent());
    }

    public function testSwooleBackstopLogsEveryFailureItSwallows(): void
    {
        $exception = new RuntimeException(self::LEAKY_MESSAGE);

        $this->swooleBackstop($exception, new Request('GET', '/api/report'), debug: false);

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('error', $this->logger->records[0]['level']);
        $this->assertSame($exception, $this->logger->records[0]['context']['exception'] ?? null);
    }

    public function testSwooleBackstopCarriesSecurityHeaders(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report'),
            debug: false
        );

        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeader('Referrer-Policy'));
    }

    public function testSwooleBackstopEmitsHstsOnlyOnSecureRequests(): void
    {
        $plain = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report'),
            debug: false
        );
        $this->assertNull($plain->getHeader('Strict-Transport-Security'));

        $secure = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report', [], [], [], ['HTTPS' => 'on']),
            debug: false
        );
        $this->assertStringContainsString('max-age=31536000', (string) $secure->getHeader('Strict-Transport-Security'));
    }

    public function testSwooleBackstopNegotiatesJsonForApiClients(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report', headers: ['Accept' => 'application/json']),
            debug: false
        );

        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
        $this->assertSame(['error' => 'Internal Server Error'], json_decode((string) $response->getContent(), true));
    }

    public function testSwooleBackstopRendersHtmlForBrowsers(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report'),
            debug: false
        );

        $this->assertStringContainsString('text/html', (string) $response->getHeader('Content-Type'));
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
    }

    public function testSwooleBackstopPreservesHttpExceptionStatusAndMessage(): void
    {
        $response = $this->swooleBackstop(
            new HttpException('Token has expired', 401),
            new Request('GET', '/api/report', headers: ['Accept' => 'application/json']),
            debug: false
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => 'Token has expired'], json_decode((string) $response->getContent(), true));
    }

    public function testSwooleBackstopHonoursDebugModeLikeTheKernelDoes(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            new Request('GET', '/api/report', headers: ['Accept' => 'application/json']),
            debug: true
        );

        // Debug mode is a deliberate, application-wide switch. The adapter must follow
        // the same flag as the kernel rather than hardcoding its own masking policy.
        $this->assertStringContainsString('SQLSTATE', $response->getContent());
    }

    public function testSwooleBackstopSurvivesABrokenErrorHandler(): void
    {
        $container = $this->makeContainer(debug: false);
        $container->singleton(
            ErrorHandlerMiddleware::class,
            function (): ErrorHandlerMiddleware {
                throw new RuntimeException('Logger is unavailable: ' . self::LEAKY_MESSAGE);
            }
        );

        $adapter = $this->bootSwoole($container);

        $response = $this->invokeBackstop($adapter, new RuntimeException(self::LEAKY_MESSAGE), new Request('GET', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
    }

    public function testSwooleBackstopSurvivesAnUnbootedAdapter(): void
    {
        // No Application, therefore no container, therefore no error handler at all.
        // A worker must still answer instead of dying on the exception.
        $adapter = new SwooleAdapter();

        $response = $this->invokeBackstop($adapter, new RuntimeException(self::LEAKY_MESSAGE), new Request('GET', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function testSwooleBackstopHandlesAFailureThatHappensBeforeTheRequestExists(): void
    {
        $response = $this->swooleBackstop(
            new RuntimeException(self::LEAKY_MESSAGE),
            null,
            debug: false
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ---------------------------------------------------------------------
    // RoadRunner: the full worker loop, end to end
    // ---------------------------------------------------------------------

    public function testRoadRunnerWorkerLoopMasksUnhandledException(): void
    {
        $worker = new QueueWorkerStub([
            new ServerRequest('GET', '/api/report'),
            null,
        ]);

        $this->runRoadRunner($worker, static fn (): never => throw new RuntimeException(self::LEAKY_MESSAGE));

        $response = $worker->responses[0];

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getBody());
        $this->assertStringNotContainsString('s3cr3t', (string) $response->getBody());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertCount(1, $this->logger->records);
    }

    public function testRoadRunnerWorkerLoopKeepsServingAfterAFailure(): void
    {
        $worker = new QueueWorkerStub([
            new ServerRequest('GET', '/api/boom'),
            new ServerRequest('GET', '/api/ok'),
            null,
        ]);

        $calls = 0;
        $this->runRoadRunner($worker, static function () use (&$calls): Response {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException(self::LEAKY_MESSAGE);
            }
            return Response::json(['status' => 'ok']);
        });

        // One request died, the worker answered it AND answered the next one. A worker
        // that exits its loop on an exception takes the whole server down with it.
        $this->assertSame(500, $worker->responses[0]->getStatusCode());
        $this->assertSame(200, $worker->responses[1]->getStatusCode());
        $this->assertSame('{"status":"ok"}', (string) $worker->responses[1]->getBody());
        $this->assertSame(2, $calls);
    }

    public function testRoadRunnerHappyPathResponseIsUntouchedByTheBoundary(): void
    {
        $worker = new QueueWorkerStub([
            new ServerRequest('GET', '/health'),
            null,
        ]);

        $this->runRoadRunner($worker, static fn (): Response => Response::json(['status' => 'ok'])->setHeader('X-Custom', 'kept'));

        $response = $worker->responses[0];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', (string) $response->getBody());
        $this->assertSame('kept', $response->getHeaderLine('X-Custom'));
        $this->assertCount(0, $this->logger->records);
    }

    public function testRoadRunnerWorkerLoopMasksFailureEvenWithoutAnyClientContext(): void
    {
        $worker = new QueueWorkerStub([
            new ServerRequest('GET', '/api/report'),
            null,
        ]);

        $this->runRoadRunner($worker, static fn (): never => throw new RuntimeException(self::LEAKY_MESSAGE));

        $this->assertStringNotContainsString('SQLSTATE', (string) $worker->responses[0]->getBody());
    }

    // ---------------------------------------------------------------------
    // Parity: the boundary is one implementation, so both adapters must agree
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{Throwable, bool, array<string, string>, array<string, string>}>
     */
    public static function parityCases(): iterable
    {
        yield 'internal exception, production' => [
            new RuntimeException(self::LEAKY_MESSAGE),
            false,
            ['Accept' => 'application/json'],
            [],
        ];

        yield 'internal exception, browser' => [
            new RuntimeException(self::LEAKY_MESSAGE),
            false,
            [],
            [],
        ];

        yield 'internal exception, debug' => [
            new RuntimeException(self::LEAKY_MESSAGE),
            true,
            ['Accept' => 'application/json'],
            [],
        ];

        yield 'http exception' => [
            new HttpException('Token has expired', 401),
            false,
            ['Accept' => 'application/json'],
            [],
        ];

        yield 'secure transport' => [
            new RuntimeException(self::LEAKY_MESSAGE),
            false,
            ['Accept' => 'application/json'],
            ['HTTPS' => 'on'],
        ];
    }

    /**
     * Two adapters, one contract. The moment somebody hand-rolls a second copy of
     * this boundary — or edits one adapter's and forgets the other — the two responses
     * diverge, and the runtimes start answering differently for the same failure.
     *
     * @dataProvider parityCases
     */
    public function testBothAdaptersProduceIdenticalErrorResponses(
        Throwable $e,
        bool $debug,
        array $headers,
        array $serverParams
    ): void {
        $swoole = $this->invokeBackstop(
            $this->bootSwoole($this->makeContainer($debug, $this->logger)),
            $e,
            new Request('GET', '/api/report', $headers, [], $serverParams)
        );

        $container = $this->makeContainer($debug, $this->logger);
        $roadRunner = $this->invokeBackstop(
            $this->bootRoadRunner($container),
            $e,
            new Request('GET', '/api/report', $headers, [], $serverParams)
        );

        $this->assertSame($swoole->getStatusCode(), $roadRunner->getStatusCode());
        $this->assertSame((string) $swoole->getContent(), (string) $roadRunner->getContent());
        $this->assertSame($swoole->getHeaders(), $roadRunner->getHeaders());
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    private function makeContainer(bool $debug, ?LoggerInterface $logger = null): Container
    {
        $container = new Container();

        $container->singleton(LoggerInterface::class, fn (): LoggerInterface => $logger ?? new NullLogger());
        $container->singleton(SecurityMiddleware::class, fn (): SecurityMiddleware => new SecurityMiddleware([]));
        $container->singleton(ErrorPageRenderer::class, fn (): ErrorPageRenderer => new ErrorPageRenderer(''));
        $container->singleton(ErrorHandlerMiddleware::class, function (Container $c) use ($debug): ErrorHandlerMiddleware {
            return new ErrorHandlerMiddleware(
                renderer: $c->get(ErrorPageRenderer::class),
                logger: $c->get(LoggerInterface::class),
                debug: $debug
            );
        });

        return $container;
    }

    /**
     * @param callable(Request): Response $handler
     */
    private function runRoadRunner(QueueWorkerStub $worker, callable $handler): void
    {
        $container = $this->makeContainer(debug: false, logger: $this->logger);

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);
        $app->method('handle')->willReturnCallback($handler);

        $adapter = new RoadRunnerAdapter($worker);
        $adapter->boot($app);
        $adapter->run();
    }

    private function bootSwoole(Container $container): SwooleAdapter
    {
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $adapter = new SwooleAdapter();
        $adapter->boot($app);

        return $adapter;
    }

    private function bootRoadRunner(Container $container): RoadRunnerAdapter
    {
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $adapter = new RoadRunnerAdapter(new QueueWorkerStub([]));
        $adapter->boot($app);

        return $adapter;
    }

    private function swooleBackstop(Throwable $e, ?Request $request, bool $debug): Response
    {
        return $this->invokeBackstop(
            $this->bootSwoole($this->makeContainer(debug: $debug, logger: $this->logger)),
            $e,
            $request
        );
    }

    /**
     * The Swoole request closure is registered on a live Swoole server, which cannot be
     * started inside PHPUnit. The closure delegates to the adapter's backstop, so the
     * contract is asserted on that unit instead of on the server lifecycle.
     */
    private function invokeBackstop(object $adapter, Throwable $e, ?Request $request): Response
    {
        $method = new ReflectionMethod($adapter, 'renderFailure');

        return $method->invoke($adapter, $e, $request);
    }
}

/**
 * Minimal PSR-7 RoadRunner worker: hands out queued requests, records what was sent back.
 */
final class QueueWorkerStub implements PSR7WorkerInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    /**
     * @param list<ServerRequestInterface|null> $requests
     */
    public function __construct(private array $requests) {}

    public function waitRequest(): ?ServerRequestInterface
    {
        return array_shift($this->requests);
    }

    public function respond(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    public function getWorker(): WorkerInterface
    {
        throw new RuntimeException('Not used: the stub hands out requests directly.');
    }
}

/**
 * PSR-3 logger that keeps everything it was told, so tests can assert observability.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}