<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Http\Middleware;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\Debug\AsyncDumper;
use Phue\Core\Http\Middleware\DumperFlushMiddleware;
use Phue\Core\Response;
use Phue\Tests\Support\RequestFactory;

/**
 * Unit tests for the middleware that flushes collected dumps into the response.
 *
 * This is the transport half of the dumping feature: the dumper collects, this
 * hands the payload to whatever the client is — the DevTool script tag for HTML,
 * a `__dumps` key for JSON/XHR — and it must never write to the process output,
 * because in a persistent runtime that lands in the server console instead of
 * the response the browser asked for.
 *
 * @package Phue\Tests\Core\Http\Middleware
 */
#[Group('unit')]
final class DumperFlushMiddlewareTest extends TestCase
{
    private ?Container $previousContainer = null;

    private Container $container;

    private AsyncDumper $dumper;

    private DumperFlushMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->container          = new Container();
        $this->dumper             = new AsyncDumper();

        $dumper = $this->dumper;

        $this->container->set(AsyncDumper::class, static fn(): AsyncDumper => $dumper);
        Container::setInstance($this->container);

        $this->middleware = new DumperFlushMiddleware($this->container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    // ------------------------------------------------------------- html

    public function testHtmlResponseCarriesTheDumpsAsAScriptTag(): void
    {
        $this->dump('user', 'Makarios', 'db');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::html('<html><body><h1>Docs</h1></body></html>')
        )->getContent();

        $this->assertStringContainsString('window.__PHUE_DUMPS', $content);
        $this->assertStringContainsString('Makarios', $content);
    }

    public function testTheScriptTagIsInjectedBeforeTheClosingBodyTag(): void
    {
        $this->dump('value');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::html('<html><body>ok</body></html>')
        )->getContent();

        $this->assertMatchesRegularExpression(
            '#window\.__PHUE_DUMPS.*</script>\s*</body>#s',
            $content,
            'A script injected after </body> never runs.'
        );
    }

    public function testTheInjectedPayloadIsValidJsonThatMatchesTheDumper(): void
    {
        $this->dump('value', ['id' => 7]);

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::html('<html><body>ok</body></html>')
        )->getContent();

        $this->assertSame(
            1,
            preg_match('#window\.__PHUE_DUMPS = (\[.*?\]);</script>#s', $content, $matches),
            'the payload must be a single assignable JSON array'
        );

        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $dump    = $this->dumper->getDumps()[0];

        $this->assertCount(1, $payload);
        $this->assertSame($dump['label'], $payload[0]['label']);
        $this->assertSame($dump['group'], $payload[0]['group']);
        $this->assertSame($dump['value'], $payload[0]['value']);
        $this->assertSame($dump['request_id'], $payload[0]['request_id']);
    }

    public function testTheInjectedPayloadCannotBreakOutOfTheScriptElement(): void
    {
        $this->dumper->dump(['markup' => '</script><script>alert(1)</script>']);

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::html('<html><body>ok</body></html>')
        )->getContent();

        $this->assertSame(
            1,
            substr_count($content, '</script>'),
            'A dump value containing </script> closes the element early and runs as script.'
        );
        $this->assertDoesNotMatchRegularExpression('#<script>alert\(1\)</script>#', $content);
    }

    public function testResponseWithoutDumpsIsLeftUntouched(): void
    {
        $body = '<html><body>ok</body></html>';

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::html($body)
        )->getContent();

        $this->assertSame($body, $content);
    }

    // ------------------------------------------------------------- json

    public function testJsonResponseCarriesTheDumpsUnderTheDumpsKey(): void
    {
        $this->dump('value');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/api/docs'),
            static fn(): Response => Response::json(['doc' => 'getting-started'])
        )->getContent();

        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('getting-started', $payload['doc']);
        $this->assertCount(1, $payload['__dumps']);
        $this->assertSame('value', $payload['__dumps'][0]['label']);
    }

    public function testXhrRequestIsTreatedAsJsonEvenWhenTheResponseIsHtml(): void
    {
        $this->dump('value');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs', [], ['X-Requested-With' => 'XMLHttpRequest']),
            static fn(): Response => Response::html('<html><body>ok</body></html>')
        )->getContent();

        $this->assertStringNotContainsString('window.__PHUE_DUMPS', $content);
    }

    public function testBinaryResponseIsLeftUntouched(): void
    {
        $this->dump('value');

        $response = new Response('raw-bytes', 200);
        $response->setHeader('Content-Type', 'application/octet-stream');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/download'),
            static fn(): Response => $response
        )->getContent();

        $this->assertSame('raw-bytes', $content);
    }

    // ------------------------------------------------------------- safety

    public function testFlushingNeverWritesToTheOutputBuffer(): void
    {
        $this->dump('value');

        ob_start();

        try {
            $this->middleware->__invoke(
                RequestFactory::get('/docs'),
                static fn(): Response => Response::html('<html><body>ok</body></html>')
            );
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame('', $output);
    }

    public function testStatusCodeAndHeadersSurviveTheFlush(): void
    {
        $this->dump('value');

        $response = $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static function (): Response {
                $response = Response::html('<html><body>ok</body></html>', 404);
                $response->setHeader('X-Debug', 'on');

                return $response;
            }
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('on', $response->getHeader('X-Debug'));
    }

    public function testEveryDumpOfTheRequestIsFlushed(): void
    {
        $this->dump('first');
        $this->dump('second');
        $this->dump('third');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/docs'),
            static fn(): Response => Response::json(['ok' => true])
        )->getContent();

        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(3, $payload['__dumps']);
        $this->assertSame(
            ['first', 'second', 'third'],
            array_column($payload['__dumps'], 'label')
        );
    }

    public function testFlushedPayloadIsJsonSafeForRedactedValues(): void
    {
        $this->dumper->dump(['password' => 'hunter2', 'id' => 1], 'user');

        $content = (string) $this->middleware->__invoke(
            RequestFactory::get('/api/docs'),
            static fn(): Response => Response::json(['ok' => true])
        )->getContent();

        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('***REDACTED***', $payload['__dumps'][0]['value']['password']);
        $this->assertStringNotContainsString('hunter2', $content);
    }

    /**
     * Records one dump.
     *
     * @param string $label Label to record under
     * @param mixed $value Value to record
     * @param string $group Group to record under
     * @return void
     */
    private function dump(string $label, mixed $value = 'value', string $group = 'default'): void
    {
        $this->dumper->dump($value, $label, $group);
    }
}