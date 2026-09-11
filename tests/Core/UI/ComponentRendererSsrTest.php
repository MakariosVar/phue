<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use Phue\Core\Ssr\NullSsrEngine;
use Phue\Core\Ssr\SsrEngine;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ComponentRendererSsrTest — locks the Phase 3 SSR seam in ComponentRenderer.
 *
 * When a View has no resolved `html`, an injected SsrEngine renders the
 * template+state into HTML that flows into the existing `View::$html` slot.
 * The seam must be:
 *  - invisible to HTML-first views (manual html wins, BC),
 *  - transparent to layout / SPA / shell pipeline,
 *  - strictly fail-open: engine null ⇒ today's template-only CSR path.
 *
 * Uses a recording fake SsrEngine — no sockets, no Bun.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
#[Group('ssr')]
final class ComponentRendererSsrTest extends TestCase
{
    private AssetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new AssetRegistry();
    }

    private function renderer(?SsrEngine $engine = null, ?callable $uriResolver = null, ?callable $requestResolver = null): ComponentRenderer
    {
        return new ComponentRenderer(
            new HtmlShellRenderer($this->registry),
            $this->registry,
            uriResolver: $uriResolver,
            requestResolver: $requestResolver,
            ssrEngine: $engine
        );
    }

    public function testViewWithoutHtmlIsRenderedByEngine(): void
    {
        $engine = RecordingSsrEngine::returning('<main><h2>Rendered by Vue</h2></main>');
        $renderer = $this->renderer($engine);

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello'], ['/assets/module.js']);

        $html = $renderer->render('Demo', $view)->getContent();

        $this->assertSame(1, $engine->calls);
        $this->assertStringContainsString(
            '<div id="app"><main><h2>Rendered by Vue</h2></main></div>',
            $html
        );
        $this->assertStringNotContainsString('{{ msg }}', $html);
        $this->assertStringContainsString('src="/assets/module.js"', $html);
    }

    public function testManualHtmlWinsOverEngine(): void
    {
        $engine = RecordingSsrEngine::returning('<main>SSR</main>');
        $renderer = $this->renderer($engine);

        $view = new View('<div>{{ msg }}</div>', [], [], null, '<div class="manual">HTML-FIRST</div>');

        $html = $renderer->render('Demo', $view)->getContent();

        $this->assertSame(0, $engine->calls, 'Engine must never be consulted when View already carries html (BC).');
        $this->assertStringContainsString('<div class="manual">HTML-FIRST</div>', $html);
    }

    public function testEngineReceivesTemplateStateAndContext(): void
    {
        $engine = RecordingSsrEngine::returning('<div>ok</div>');
        $renderer = $this->renderer($engine, uriResolver: fn (): string => '/products?page=2');

        $view = new View('<ul><li v-for="i in items">{{ i }}</li></ul>', ['items' => [1, 2]]);

        $renderer->render('Products', $view);

        $this->assertSame('<ul><li v-for="i in items">{{ i }}</li></ul>', $engine->lastTemplate);
        $this->assertSame(['items' => [1, 2]], $engine->lastState);
        $this->assertSame(['name' => 'Products', 'uri' => '/products?page=2'], $engine->lastContext);
    }

    public function testConfiguredLayoutWrapsSsrHtmlOutsideAppContainer(): void
    {
        $baseDir = sys_get_temp_dir() . '/phue_ssr_layout_' . uniqid('', true);
        $viewsDir = $baseDir . '/views';
        mkdir($viewsDir, 0777, true);

        try {
            file_put_contents($viewsDir . '/AppLayout.phue', <<<'PHUE'
<php>
$content = $content ?? '';
return [
    'html' => sprintf('<div class="layout-wrap"><header>NAV</header><main>%s</main></div>', $content),
];
</php>

<template>
    layout template ignored when html is provided
</template>
PHUE);

            $engine = RecordingSsrEngine::returning('<h2>Rendered by Vue</h2>');
            $renderer = new ComponentRenderer(
                new HtmlShellRenderer($this->registry),
                $this->registry,
                new PhueComponentLoader($viewsDir),
                'AppLayout',
                ssrEngine: $engine
            );

            $view = new View('<div>{{ msg }}</div>', ['msg' => 'hi']);

            $html = $renderer->render('Demo', $view)->getContent();

            $this->assertStringContainsString(
                '<div class="layout-wrap"><header>NAV</header><main><div id="app"><h2>Rendered by Vue</h2></div></main></div>',
                $html
            );
            $this->assertStringNotContainsString('{{ msg }}', $html);
        } finally {
            $this->removeTree($baseDir);
        }
    }

    public function testSpaResponseCarriesSsrHtmlNotRawTemplate(): void
    {
        $engine = RecordingSsrEngine::returning('<h2>Rendered by Vue</h2>');
        $request = new Request('GET', '/products', headers: ['X-Phue-SPA' => 'true']);
        $renderer = $this->renderer($engine, requestResolver: fn (): Request => $request);

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

        $response = $renderer->render('Products', $view);

        $this->assertSame('application/json', $response->getHeader('Content-Type'));

        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertStringContainsString('<h2>Rendered by Vue</h2>', $payload['html']);
        $this->assertStringNotContainsString('{{ msg }}', $payload['html']);
        $this->assertSame(['msg' => 'hello'], $payload['state']);
        $this->assertArrayHasKey('title', $payload);
        $this->assertArrayHasKey('scripts', $payload);
    }

    public function testSpaPayloadBypassesLayoutChromeEvenWhenLayoutConfigured(): void
    {
        $baseDir = __DIR__ . '/../../tmp/component-ssr-spa-' . uniqid();
        $viewsDir = $baseDir . '/views';
        mkdir($viewsDir, 0777, true);

        file_put_contents($viewsDir . '/AppLayout.phue', <<<PHUE
<header>NAV</header>
<main>{{ content }}</main>
PHUE);

        try {
            $engine = RecordingSsrEngine::returning('<h2>Rendered by Vue</h2>');
            $request = new Request('GET', '/products', headers: ['X-Phue-SPA' => 'true']);
            $renderer = new ComponentRenderer(
                new HtmlShellRenderer($this->registry),
                $this->registry,
                new PhueComponentLoader($viewsDir),
                'AppLayout',
                requestResolver: fn (): Request => $request,
                ssrEngine: $engine
            );

            $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

            $response = $renderer->render('Demo', $view);

            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertStringContainsString('<h2>Rendered by Vue</h2>', $payload['html']);
            $this->assertStringNotContainsString('<header>NAV</header>', $payload['html']);
            $this->assertStringNotContainsString('<div id="app">', $payload['html']);
            $this->assertSame(['msg' => 'hello'], $payload['state']);
        } finally {
            $this->removeTree($baseDir);
        }
    }

    public function testWhitelistedComponentsAreForwardedToSsrContext(): void
    {
        $baseDir = sys_get_temp_dir() . '/phue_ssr_components_' . uniqid('', true);
        $viewsDir = $baseDir . '/views';
        mkdir($viewsDir, 0777, true);

        file_put_contents($viewsDir . '/PhButton.phue', <<<'PHUE'
<template>
<button>{{ label }}</button>
</template>

<script>
export default {
    props: { label: { type: String, default: '' } }
};
</script>
PHUE);

        try {
            $loader = new PhueComponentLoader($viewsDir);
            $hash = $loader->moduleHash('PhButton');
            $this->assertIsString($hash);

            $registry = new ComponentLoaderRegistry();
            $registry->addLoader($loader);

            $engine = RecordingSsrEngine::returning('<button>ok</button>');
            $renderer = new ComponentRenderer(
                new HtmlShellRenderer($this->registry),
                $this->registry,
                $loader,
                ssrEngine: $engine,
                loaderRegistry: $registry
            );

            $view = new View('<div><PhButton/></div>', [
                'phuiUrls' => [
                    'PhButton' => "/assets/phue/PhButton.{$hash}.phue.js",
                ],
            ]);

            $renderer->render('Demo', $view);

            $this->assertArrayHasKey('components', $engine->lastContext, 'SSR context must carry the whitelisted components.');
            $this->assertArrayHasKey('PhButton', $engine->lastContext['components']);
            $this->assertStringStartsWith(
                'export const __phueTemplate',
                $engine->lastContext['components']['PhButton']
            );
        } finally {
            $this->removeTree($baseDir);
        }
    }

    public function testUnresolvableWhitelistedComponentsFailOpenWithoutComponentsKey(): void
    {
        $engine = RecordingSsrEngine::returning('<div>ok</div>');
        $renderer = $this->renderer($engine);

        $view = new View('<div><Missing/></div>', [
            'phuiUrls' => [
                'Missing' => '/assets/phue/Missing.deadbeef.phue.js',
            ],
        ]);

        $response = $renderer->render('Demo', $view);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('components', $engine->lastContext, 'Unresolvable components must fail open.');
    }

    public function testEngineReturningNullFailsOpenToTemplatePath(): void
    {
        $engine = RecordingSsrEngine::returning(null);
        $renderer = $this->renderer($engine);

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

        $response = $renderer->render('Demo', $view);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $engine->calls);
        $this->assertStringContainsString('<div>{{ msg }}</div>', $response->getContent());
    }

    public function testRendererWithoutEngineFallsBackToTemplatePath(): void
    {
        $renderer = $this->renderer();

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

        $response = $renderer->render('Demo', $view);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<div>{{ msg }}</div>', $response->getContent());
    }

    public function testNullSsrEngineFallsBackToTemplatePath(): void
    {
        $renderer = $this->renderer(new NullSsrEngine());

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

        $html = $renderer->render('Demo', $view)->getContent();

        $this->assertStringContainsString('<div>{{ msg }}</div>', $html);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

/**
 * Recording fake SsrEngine (symmetrical to ComponentRenderer's `state:` call).
 * Returns a fixed html payload while capturing template/state/context + calls.
 */
final class RecordingSsrEngine implements SsrEngine
{
    public int $calls = 0;

    public ?string $lastTemplate = null;

    /** @var array<string, mixed> */
    public array $lastState = [];

    /** @var array<string, mixed> */
    public array $lastContext = [];

    private function __construct(private ?string $html)
    {
    }

    public static function returning(?string $html): self
    {
        return new self($html);
    }

    public function render(string $template, array $state = [], array $context = []): ?string
    {
        $this->calls++;
        $this->lastTemplate = $template;
        $this->lastState = $state;
        $this->lastContext = $context;

        return $this->html;
    }
}