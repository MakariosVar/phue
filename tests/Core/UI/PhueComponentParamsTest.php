<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\TestComponentLoader;

/**
 * Contracts for passing controller params into a component's <php> block:
 *  - params are visible as variables during evaluation,
 *  - params are per-render (never poisoned by the parsed-structure cache).
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueComponentParamsTest extends TestCase
{
    private string $componentsDir;
    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = sys_get_temp_dir() . '/phue_params_' . uniqid();
        mkdir($this->componentsDir, 0777, true);

        file_put_contents($this->componentsDir . '/Parameterized.phue', <<<'PHUE'
<php>
return [
    'title' => 'Param: ' . ($testValue ?? 'none'),
    'state' => [
        'greeting' => 'Hello ' . ($testValue ?? 'nobody'),
    ],
];
</php>

<template><div id="body">{{ greeting }}</div></template>
PHUE);

        $this->loader = TestComponentLoader::make($this->componentsDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->componentsDir}/*") ?: []);
        rmdir($this->componentsDir);
    }

    private function renderer(): ComponentRenderer
    {
        $registry = new AssetRegistry();

        return new ComponentRenderer(new HtmlShellRenderer($registry), $registry, $this->loader);
    }

    /**
     * @return array<mixed> decoded __INITIAL_STATE__ payload
     */
    private function extractInitialState(string $html): array
    {
        preg_match('/window\.__INITIAL_STATE__ = (.*?);\s*</s', $html, $m);
        $this->assertArrayHasKey(1, $m, 'expected an __INITIAL_STATE__ assignment in the shell');

        return json_decode($m[1], true) ?? [];
    }

    public function testParamsAreVisibleInsidePhpBlock(): void
    {
        $html = $this->renderer()
            ->render('Parameterized', ['testValue' => 'Widget Squad'])
            ->getContent();

        $this->assertStringContainsString('<title>Param: Widget Squad</title>', $html);
        $this->assertSame('Hello Widget Squad', $this->extractInitialState($html)['greeting']);
    }

    public function testNoParamsCoalesceToDefaults(): void
    {
        $html = $this->renderer()->render('Parameterized')->getContent();

        $this->assertStringContainsString('<title>Param: none</title>', $html);
        $this->assertSame('Hello nobody', $this->extractInitialState($html)['greeting']);
    }

    public function testDifferentParamsNeverPoisonTheSharedCache(): void
    {
        $renderer = $this->renderer();

        $first = $this->extractInitialState(
            $renderer->render('Parameterized', ['testValue' => 'first'])->getContent()
        );
        $second = $this->extractInitialState(
            $renderer->render('Parameterized', ['testValue' => 'second'])->getContent()
        );

        $this->assertSame('Hello first', $first['greeting']);
        $this->assertSame('Hello second', $second['greeting']);
        $this->assertNotSame($first, $second);
    }

    public function testRendersAgainAfterParamChange(): void
    {
        $renderer = $this->renderer();

        $a = $renderer->render('Parameterized', ['testValue' => 'A'])->getContent();
        $b = $renderer->render('Parameterized', ['testValue' => 'B'])->getContent();

        $this->assertStringContainsString('<title>Param: A</title>', $a);
        $this->assertStringContainsString('<title>Param: B</title>', $b);
    }
}