<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\Ssr\NullSsrEngine;
use Phue\Core\Ssr\SsrEngine;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NullSsrEngineTest — locks down the Null Object fallback used when SSR is
 * globally disabled (config `ssr.enabled = false`).
 *
 * The Null engine is a pure no-op: rendering always yields null (which signals
 * the existing template-only / CSR path downstream), it never talks to any
 * transport, and it can never throw.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('component')]
final class NullSsrEngineTest extends TestCase
{
    private NullSsrEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new NullSsrEngine();
    }

    public function testImplementsTheSsrEngineContract(): void
    {
        $this->assertInstanceOf(SsrEngine::class, $this->engine);
    }

    public function testRenderAlwaysReturnsNull(): void
    {
        $cases = [
            ['template' => '', 'data' => [], 'context' => []],
            ['template' => '<div>{{ testValue }}</div>', 'data' => ['testValue' => 'x'], 'context' => []],
            ['template' => '<h2>Phue App</h2>', 'data' => [], 'context' => ['name' => 'Home', 'uri' => '/']],
        ];

        foreach ($cases as $case) {
            $this->assertNull(
                $this->engine->render($case['template'], $case['data'], $case['context'])
            );
        }
    }

    public function testRenderIsPureNoOpWithoutThrowing(): void
    {
        try {
            $this->engine->render('<div>{{ nope }}</div>', ['nope' => 1]);
            $this->addToAssertionCount(1);
        } catch (\Throwable $exception) {
            $this->fail('Null engine must never throw: ' . $exception->getMessage());
        }
    }
}