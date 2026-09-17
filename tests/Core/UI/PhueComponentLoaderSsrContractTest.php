<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\TemplateCompilerBridge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhueComponentLoaderSsrContractTest — freezes the SSR module self-containment
 * contract for buildForSsr() against the DocSection regression.
 *
 * The Sidecar uses Bun's dynamic `import()` to EVALUATE every module shipped in
 * the envelope (ssr/server.ts:loadModuleFromSource). An identifier that is
 * referenced inside a module's `components: { ... }` object but is never
 * bound in that module (no import, no const/let/var/function) therefore
 * crashes evaluation with `ReferenceError: X is not defined`, the sidecar
 * answers `{ ok:false, COMPILE_ERROR }`, the engine fail-opens to `null` and
 * the raw `<ph-...>` template leaks into `#app` (hydration mismatch).
 *
 * Historical bug (Sept 2026): buildForSsr injected `components: { PhBadge,
 * PhHeading }` for discovered children WITHOUT importing them (buildFor does
 * import them on the client), so `DocSection`/`PhNavigation` modules crashed
 * on import inside the sidecar.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
#[Group('ssr')]
final class PhueComponentLoaderSsrContractTest extends TestCase
{
    private string $baseDir;
    private ComponentLoaderRegistry $registry;
    private PhueComponentLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_ssr_contract_' . uniqid('', true);
        mkdir($this->baseDir, 0777, true);

        $bridge = new StaticCompilerBridgeStub();

        $this->loader = new PhueComponentLoader($this->baseDir, compilerBridge: $bridge);
        $this->registry = new ComponentLoaderRegistry();
        $this->loader->setRegistry($this->registry);
        $this->registry->addLoader($this->loader);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function write(string $name, string $content): void
    {
        file_put_contents($this->baseDir . '/' . $name . '.phue', $content);
    }

    /**
     * Feeds the two children (PhBadge, PhHeading) plus a fixture parent of the
     * requested "shape": scriptless, createSSRApp-scripted, or export-default
     * scripted — the three branches buildForSsr() currently handles.
     */
    private function writeKit(string $kind): string
    {
        $this->write('PhBadge', <<<'PHUE'
<template>
<span class="badge">{{ label }}</span>
</template>
PHUE);
        $this->write('PhHeading', <<<'PHUE'
<template>
<h2><slot/></h2>
</template>
PHUE);

        $children = '<ph-badge label="ok"></ph-badge><ph-heading>Docs</ph-heading>';
        $parentName = match ($kind) {
            'scriptless'   => 'SectScriptless',
            'createApp'    => 'SectCreateApp',
            'exportDefault' => 'SectExportDefault',
            default        => throw new \LogicException("Unknown kind: {$kind}"),
        };

        $script = match ($kind) {
            'scriptless'  => '',
            'createApp'   => <<<'JS'
import { createSSRApp } from 'vue';
export default { setup() { return {}; } };
JS,
            'exportDefault' => 'export default { name: \'Section\' };',
        };

        $this->write($parentName, <<<PHUE
<template>
<div class="section">{$children}</div>
</template>

<script>
{$script}
</script>
PHUE);

        return $parentName;
    }

    /**
     * The regression gate, scriptless parent branch (DocSection shape):
     * every identifier referenced in a `components: {}` object of a generated
     * SSR module MUST be bound inside that same module (import / const /
     * let / var / function). Otherwise Bun's dynamic import in the sidecar
     * throws ReferenceError at evaluation time.
     */
    public function testScriptlessParentComponentDoesNotReferenceUndefinedChildIdentifiers(): void
    {
        $parentName = $this->writeKit('scriptless');

        $module = $this->loader->buildForSsr($parentName);
        $this->assertIsString($module, "buildForSsr must succeed for {$parentName}");

        $this->assertSsrModuleComponentIdentifiersAreBound($module, $parentName);
    }

    /**
     * The regression gate, createSSRApp-createApp proxy branch (PhNavigation shape).
     */
    public function testCreateAppProxyParentDoesNotReferenceUndefinedChildIdentifiers(): void
    {
        $parentName = $this->writeKit('createApp');

        $module = $this->loader->buildForSsr($parentName);
        $this->assertIsString($module, "buildForSsr must succeed for {$parentName}");

        $this->assertSsrModuleComponentIdentifiersAreBound($module, $parentName);
    }

    /**
     * The regression gate, plain export-default script branch.
     */
    public function testExportDefaultParentDoesNotReferenceUndefinedChildIdentifiers(): void
    {
        $parentName = $this->writeKit('exportDefault');

        $module = $this->loader->buildForSsr($parentName);
        $this->assertIsString($module, "buildForSsr must succeed for {$parentName}");

        $this->assertSsrModuleComponentIdentifiersAreBound($module, $parentName);
    }

    /**
     * Same gate but the child is scripted as well (client-side merge contract).
     */
    public function testSsrModuleReferencesAreBoundWhenChildrenAreScripted(): void
    {
        $this->write('PhBadge', <<<'PHUE'
<template>
<span class="badge">{{ label }}</span>
</template>
<script>
export default { props: { label: { type: String, default: '' } } };
</script>
PHUE);
        $this->write('PhHeading', <<<'PHUE'
<template>
<h2><slot/></h2>
</template>
<script>
export default { name: 'PhHeading' };
</script>
PHUE);
        $this->write('SectScriptedChildren', <<<'PHUE'
<template>
<div class="section"><ph-badge label="ok"></ph-badge><ph-heading>Docs</ph-heading></div>
</template>
PHUE);

        $module = $this->loader->buildForSsr('SectScriptedChildren');

        $this->assertIsString($module);
        $this->assertSsrModuleComponentIdentifiersAreBound($module, 'SectScriptedChildren');
    }

    /**
     * Identifiers bound via the createSSRApp proxy path (e.g. PhNavigation)
     * must also be importable by the sidecar.
     */
    public function testSsrModuleCreateAppProxyReferencesAreBound(): void
    {
        $this->write('PhTheme', <<<'PHUE'
<template>
<button type="button">{{ label }}</button>
</template>
<script>
export default { props: { label: { type: String, default: 'theme' } } };
</script>
PHUE);

        // PhNavigation references <ph-theme> — the original regression surface
        // proven live: `components: { ...(opts.components || {}), PhTheme }`
        // with no PhTheme binding anywhere in the module.
        $this->write('PhNavigation', <<<'PHUE'
<template>
<div class="nav"><ph-theme aria-label="Toggle theme"></ph-theme></div>
</template>
<script>
import { createSSRApp } from 'vue';
export default { setup() { return {}; } };
</script>
PHUE);

        $module = $this->loader->buildForSsr('PhNavigation');

        $this->assertIsString($module);
        $this->assertSsrModuleComponentIdentifiersAreBound($module, 'PhNavigation');
    }

    public function testChildlessSsrModuleHasNoComponentsPropAtAll(): void
    {
        $this->write('Plain', <<<'PHUE'
<template>
<div>No dependencies</div>
</template>
PHUE);

        $module = $this->loader->buildForSsr('Plain');

        $this->assertIsString($module);
        $this->assertStringNotContainsString('components:', $module, 'No children ⇒ no components object must be emitted.');
        $this->assertStringContainsString('export const __phueSsrRender = ssrRender;', $module);
        $this->assertStringContainsString('export default {', $module);
    }

    /**
     * Scans every `components: { ... }` object in an SSR module and asserts
     * that each quoted child identifier is bound in the same module.
     */
    private function assertSsrModuleComponentIdentifiersAreBound(string $module, string $context): void
    {
        // Normalize the proxy-spread shapes first: `...(opts.components || {})`
        // contained a NESTED `{}` that would have truncated the `[^}]*` block
        // capture, and `...opts` names have no module-level binding to check.
        $normalized = (string) preg_replace('/\.\.\.\(opts\.components\s*\|\|\s*\{\}\)/', '', $module);
        $normalized = (string) preg_replace('/\.\.\.opts\b/', '', $normalized);

        $blockPattern = '/components\s*:\s*\{([^}]*)\}/s';
        if (preg_match_all($blockPattern, $normalized, $blocks) === 0) {
            // No components object at all — the module is trivially self-contained.
            return;
        }

        foreach ($blocks[1] as $rawBlock) {
            $names = array_values(array_filter(
                preg_split('/[\s,]+/', $rawBlock, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                static fn (string $token): bool => preg_match('/^[A-Z_$][\w$]*$/', $token) === 1
            ));

            foreach ($names as $name) {
                $bound = $this->isIdentifierBoundInModule($module, $name);

                $this->assertTrue(
                    $bound,
                    "[{$context}] SSR module references component '{$name}' inside `components:` "
                    . "but never imports/declares it. The sidecar's Bun dynamic import() will throw "
                    . "ReferenceError: {$name} is not defined → COMPILE_ERROR → fail-open → raw \\<ph:…\\> leaks into #app."
                );
            }
        }
    }

    private function isIdentifierBoundInModule(string $module, string $name): bool
    {
        $quoted = preg_quote($name, '/');

        return preg_match('/\bimport\s+[^;]*?\b' . $quoted . '\b\s*(?:,|\s+as\s+\w+)?\s*from\b/s', $module) === 1
            || preg_match('/\b(?:const|let|var)\s+(\{?\s*)\b' . $quoted . '\b(?:\s*[,}=\s])/s', $module) === 1
            || preg_match('/\bfunction\s+' . $quoted . '\s*\(/s', $module) === 1;
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
 * Deterministic compiler bridge double: returns a minimal ssrRender body
 * without any socket round-trip, isolating the module-shaping logic from the
 * Bun sidecar compile endpoint.
 */
final class StaticCompilerBridgeStub extends TemplateCompilerBridge
{
    public function compile(string $template, bool $ssr = false): string
    {
        return 'const ssrRender = (_ctx, _push, _parent, _attrs) => { _push(`<div>stub</div>`) };';
    }
}