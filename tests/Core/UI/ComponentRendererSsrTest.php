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
use Phue\Tests\Support\TestComponentLoader;

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
                TestComponentLoader::make($viewsDir),
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
                TestComponentLoader::make($viewsDir),
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

    public function testDiscoveredComponentsAreForwardedToSsrContext(): void
    {
        $baseDir = sys_get_temp_dir() . '/phue_ssr_components_' . uniqid('', true);
        $viewsDir = $baseDir . '/views';
        mkdir($viewsDir, 0777, true);

        file_put_contents($viewsDir . '/Showcase.phue', <<<'PHUE'
<template>
<div>
  <PhButton label="hello"/>
  <button>native button must be ignored</button>
</div>
</template>
PHUE);

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
            $loader = TestComponentLoader::make($viewsDir);
            $registry = new ComponentLoaderRegistry();
            $loader->setRegistry($registry);
            $registry->addLoader($loader);

            $engine = RecordingSsrEngine::returning('<div>ok</div>');
            $renderer = new ComponentRenderer(
                new HtmlShellRenderer($this->registry),
                $this->registry,
                $loader,
                ssrEngine: $engine,
                loaderRegistry: $registry
            );

            $response = $renderer->render('Showcase', ['testValue' => 'x']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertArrayHasKey('components', $engine->lastContext, 'SSR context must carry the discovered components.');
            $this->assertArrayHasKey('PhButton', $engine->lastContext['components']);
            $this->assertArrayNotHasKey(
                'Showcase',
                $engine->lastContext['components'],
                'The page root itself must not be registered in the SSR envelope.'
            );
            // The forwarded module must ship the precompiled render binding.
            $this->assertStringStartsWith(
                'import ',
                $engine->lastContext['components']['PhButton']
            );
            $this->assertStringContainsString(
                'export const __phueSsrRender',
                $engine->lastContext['components']['PhButton']
            );
        } finally {
            $this->removeTree($baseDir);
        }
    }

    public function testUnresolvableDiscoveredComponentFailsOpenWithoutComponentsKey(): void
    {
        $baseDir = sys_get_temp_dir() . '/phue_ssr_unresolvable_' . uniqid('', true);
        $viewsDir = $baseDir . '/views';
        mkdir($viewsDir, 0777, true);

        file_put_contents($viewsDir . '/MissingPage.phue', <<<'PHUE'
<template>
<div><Missing/></div>
</template>
PHUE);

        try {
            $loader = TestComponentLoader::make($viewsDir);
            $registry = new ComponentLoaderRegistry();
            $loader->setRegistry($registry);
            $registry->addLoader($loader);

            $engine = RecordingSsrEngine::returning('<div>ok</div>');
            $renderer = new ComponentRenderer(
                new HtmlShellRenderer($this->registry),
                $this->registry,
                $loader,
                ssrEngine: $engine,
                loaderRegistry: $registry
            );

            $response = $renderer->render('MissingPage');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertArrayNotHasKey('components', $engine->lastContext, 'Unknown components must fail open.');
        } finally {
            $this->removeTree($baseDir);
        }
    }

    public function testEngineReturningNullFailsOpenToAnEmptyMountContainer(): void
    {
        // When the SSR engine returns null the renderer must NOT put the template
        // in the response. That path used to be the "fails open" behaviour, and it
        // is the single worst failure mode in the pipeline: the client then
        // hydrates Vue against its own source text, reports a node mismatch on
        // every component, and the page never becomes interactive.
        //
        // An empty mount container is strictly better — the client mounts and
        // renders normally, so the reader gets a working page and the only loss is
        // the server render.
        $engine = RecordingSsrEngine::returning(null);
        $renderer = $this->renderer($engine);

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello'], isVueTemplate: true);

        $response = $renderer->render('Demo', $view);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $engine->calls);

        $content = $response->getContent();

        $this->assertStringNotContainsString(
            '{{ msg }}',
            $content,
            'an SSR failure must never ship the unrendered template as page content'
        );
        $this->assertStringContainsString(
            'id="app"></div>',
            $content,
            'the mount container must be EMPTY so the client renders it, rather than holding '
            . 'text the client then has to hydrate against'
        );
    }

    public function testAVueTemplateWithoutEngineLeavesTheMountContainerEmpty(): void
    {
        // No engine configured is the same situation as a failed render: there is
        // nothing to hydrate against, so the container must stay empty.
        //
        // The `isVueTemplate` flag is what separates this from the plain-View case
        // below. It is set by the loader, not inferred from the string, so a hand
        // built view containing `{{ msg }}` is still inlined — which is right: the
        // caller handed over markup, not a template.
        $renderer = $this->renderer();

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello'], isVueTemplate: true);

        $response = $renderer->render('Demo', $view);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('{{ msg }}', $response->getContent());
        $this->assertStringContainsString('id="app"></div>', $response->getContent());
    }

    public function testAPlainViewWithoutEngineIsStillInlined(): void
    {
        // The counterpart, and a feature rather than a bug: a caller that hands the
        // renderer finished HTML with no engine gets that HTML served.
        // ComponentRendererTest::testRendersShellWithInlinedTemplateTitleAndState
        // asserts the same thing; it is repeated here so the two branches of the
        // `isVueTemplate` decision sit next to each other.
        $renderer = $this->renderer();

        $view = new View('<div class="container"><h2>Hello</h2></div>', ['products' => []]);

        $response = $renderer->render('Demo', $view);

        $this->assertStringContainsString(
            '<div class="container"><h2>Hello</h2></div>',
            $response->getContent()
        );
    }

    public function testAFailedEngineOnAVueTemplateLeavesTheMountContainerEmpty(): void
    {
        $renderer = $this->renderer(RecordingSsrEngine::returning(null));

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello'], isVueTemplate: true);

        $html = $renderer->render('Demo', $view)->getContent();

        $this->assertStringNotContainsString('{{ msg }}', $html);
        $this->assertStringContainsString('id="app"></div>', $html);
    }

    public function testTheStateSurvivesEveryNonRenderedPath(): void
    {
        // Whatever the renderer decides not to render, the client cannot render
        // without the state — so it has to be serialised on every branch.
        foreach ([
            'no engine, Vue template' => [$this->renderer(), true],
            'failed engine, Vue template' => [$this->renderer(RecordingSsrEngine::returning(null)), true],
            'no engine, plain view' => [$this->renderer(), false],
        ] as $label => [$renderer, $isVueTemplate]) {
            $html = $renderer->render(
                'Demo',
                new View('<div>{{ msg }}</div>', ['msg' => 'hello'], isVueTemplate: $isVueTemplate)
            )->getContent();

            $this->assertStringContainsString(
                'hello',
                $html,
                "the initial state must still be serialised on the `{$label}` path"
            );
        }
    }

    public function testNullSsrEngineLeavesTheMountContainerEmpty(): void
    {
        // `NullSsrEngine` is what an app gets when SSR is switched off on purpose.
        // It is therefore the REFERENCE for what a non-SSR page looks like: an
        // empty mount container plus the state the client needs.
        $renderer = $this->renderer(new NullSsrEngine());

        $view = new View('<div>{{ msg }}</div>', ['msg' => 'hello']);

        $html = $renderer->render('Demo', $view)->getContent();

        $this->assertStringNotContainsString('{{ msg }}', $html);

        // The state is what makes the client render at all, so it must survive the
        // loss of SSR.
        $this->assertStringContainsString('hello', $html, 'the initial state must still be serialised');
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