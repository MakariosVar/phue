<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HydrationContractTest — locks Contract 3.4 (State <-> Template binding) and the
 * client hydration switch.
 *
 * The SSR loop only closes when every layer agrees on the SAME state keys:
 *   1. the <template> bindings resolve against the state the server ships,
 *   2. the client <script> reads only those same keys from __INITIAL_STATE__,
 *   3. the client builds through createPhueApp() and lets the factory mount — mounting
 *      happens INSIDE mount() in Vue 3, there is no `app.hydrate()` API.
 *
 * Every page below is a fixture written into a temp directory. Pinning this contract to
 * a handful of real pages made it look green until one of them was refactored and the
 * breakage went unnoticed; a fixture suite asserts the rule itself, including the failure
 * modes, so a page that drifts fails on its own terms rather than on someone else's.
 *
 * Pure PHP — no sockets, no Bun sidecar required.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('unit')]
#[Group('ssr')]
final class HydrationContractTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->rmTree($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * Writes an SFC into an isolated temp directory and returns its path.
     *
     * A real file on disk, not an in-memory structure: parseStructure() is handed a path
     * and the point is to exercise the same parser the renderer uses.
     */
    private function writePage(string $sfc, string $name = 'Page'): string
    {
        $dir = sys_get_temp_dir() . '/phue_hydration_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        $file = "{$dir}/{$name}.phue";
        file_put_contents($file, $sfc);

        return $file;
    }

    /**
     * A page that satisfies every clause of the contract.
     */
    private static function validPage(): string
    {
        return <<<'PHUE'
<php>
return [
    'title' => 'Hydration Fixture',
    'state' => [
        'testValue' => 'HomePage',
        'doc'       => 'getting-started',
        'navItems'  => [],
    ],
];
</php>
<template>
<div class="p-4">
    <h1>{{ testValue }}</h1>
    <div v-html="doc"></div>
    <ul>
        <li v-for="item in navItems" :key="item.id">{{ item.label }}</li>
    </ul>
    <slot name="footer" v-if="testValue">{{ item }}</slot>
</div>
</template>
<script>
import { ref } from 'vue';

export default function mountApp() {
    const current = ref(window.__INITIAL_STATE__.testValue);
    window.__phueApp = createPhueApp({
        setup() {
            return { current };
        },
    });
}
</script>
PHUE;
    }

    /**
     * State keys the <php> block ships, as the renderer would see them.
     *
     * @return array<int, string>
     */
    private function shippedStateKeys(string $file, array $params = []): array
    {
        $parser = new PhueTemplateParser();

        return array_keys($parser->parse($file, $params)->view->getState());
    }

    /**
     * Every identifier the template binds, with template-declared locals removed.
     *
     * An interpolation resolves against exactly two things: a key the server ships, or a
     * local the template itself introduces (a v-for alias, a slot prop). Anything else is
     * drift — the server interpolates an empty node and the client patches a hydration
     * mismatch on mount.
     *
     * @return array<int, string>
     */
    private function unresolvedBindings(string $file, array $params = []): array
    {
        $parser = new PhueTemplateParser();
        $structure = $parser->parseStructure($file);
        $template = (string) $structure['template'];

        $roots = [];

        // `{{ key }}` and `{{ obj.prop }}` -> root identifier.
        preg_match_all(
            '/\{\{\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z0-9_$]+)*)\s*\}\}/',
            $template,
            $mustaches
        );
        foreach ($mustaches[1] as $expression) {
            $roots[] = explode('.', $expression)[0];
        }

        // `v-html="key"` / `v-html="obj.prop"` -> root identifier.
        preg_match_all(
            '/v-html="\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z0-9_$]+)*)\s*"/',
            $template,
            $vHtml
        );
        foreach ($vHtml[1] as $expression) {
            $roots[] = explode('.', $expression)[0];
        }

        // Locals the template itself introduces are NOT state keys.
        preg_match_all(
            '/v-for="\s*\(?\s*([A-Za-z_$][A-Za-z0-9_$]*)/',
            $template,
            $forAliases
        );
        $locals = $forAliases[1];

        preg_match_all('/#\w+="\{([^}]*)\}"/', $template, $slotScopes);
        foreach ($slotScopes[1] as $scope) {
            foreach (explode(',', $scope) as $name) {
                $name = trim(explode('=', $name)[0]);
                if ($name !== '') {
                    $locals[] = $name;
                }
            }
        }

        $bindings = array_values(array_unique($roots));
        $keys = $this->shippedStateKeys($file, $params);

        return array_values(array_diff($bindings, $locals, $keys));
    }

    /**
     * State keys the client script reads off window.__INITIAL_STATE__.
     *
     * @return array<int, string>
     */
    private function clientStateReads(string $file): array
    {
        $parser = new PhueTemplateParser();
        $script = (string) $parser->parseStructure($file)['script'];

        preg_match_all('/__INITIAL_STATE__\??\.([A-Za-z_$][A-Za-z0-9_$]*)/', $script, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function clientScript(string $file): string
    {
        return (string) (new PhueTemplateParser())->parseStructure($file)['script'];
    }

    public function testValidPageSatisfiesEveryClauseOfTheContract(): void
    {
        $file = $this->writePage(self::validPage());

        $this->assertSame(
            [],
            $this->unresolvedBindings($file),
            'A page binding {{ testValue }} while shipping state[\'testValue\'] has no drift to report.'
        );

        $reads = $this->clientStateReads($file);
        $this->assertSame(['testValue'], $reads);

        foreach ($reads as $key) {
            $this->assertContains(
                $key,
                $this->shippedStateKeys($file),
                'The client must only read keys the server ships.'
            );
        }
    }

    /**
     * The pre-refactor failure this file existed to catch: the page shipped
     * state['value'] while the template bound {{testValue}}. The server interpolates an
     * empty text node and the client patches a hydration mismatch on mount.
     */
    public function testBindingToAKeyTheServerDoesNotShipIsDrift(): void
    {
        $file = $this->writePage(<<<'PHUE'
<php>
return [
    'title' => 'Drifted',
    'state' => [
        'value' => 'x',
    ],
];
</php>
<template>
<div>{{ testValue }}</div>
</template>
<script>
export default function mountApp() {
    window.__phueApp = createPhueApp({});
}
</script>
PHUE);

        $this->assertSame(
            ['testValue'],
            $this->unresolvedBindings($file),
            'Contract 3.4: {{ testValue }} resolves against nothing — state ships \'value\', '
            . 'not \'testValue\'.'
        );
    }

    /**
     * A v-for alias is not a state key. Asserted explicitly because treating it as one is
     * how a page whose every interpolation is an alias ends up "unguarded" — and a rename
     * of the alias then ships silently.
     *
     * Scope note: the analyzer resolves {{ }}, v-html, v-for aliases and `#name="{ ... }"`
     * slot props. Other directives (v-if and friends) are deliberately not state bindings,
     * so this fixture stays inside what the contract actually governs.
     */
    public function testForAliasesAndSlotPropsAreNotTreatedAsStateKeys(): void
    {
        $file = $this->writePage(<<<'PHUE'
<php>
return [
    'title' => 'Locals only',
    'state' => [],
];
</php>
<template>
<ul>
    <li v-for="row in rows" :key="row.id">{{ row.label }}</li>
</ul>
<template #footer="{ inner }">
    <span>{{ inner }} {{ companion }}</span>
</template>
</template>
<script>
export default function mountApp() {
    window.__phueApp = createPhueApp({});
}
</script>
PHUE);

        $this->assertSame(
            ['companion'],
            $this->unresolvedBindings($file),
            'Only identifiers that are neither a shipped key nor a template-declared local '
            . 'count as drift. `row` is a v-for alias and `inner` is a #footer slot prop.'
        );
    }

    /**
     * A page that ships state but binds none of it has no contract left to verify, and on
     * the real pages that shape came from a refactor that dropped the bindings entirely.
     */
    public function testPageWithoutAnyInterpolationIsReportedRatherThanPassing(): void
    {
        $file = $this->writePage(<<<'PHUE'
<php>
return [
    'title' => 'No bindings',
    'state' => [],
];
</php>
<template>
<div class="p-4">Static markup only.</div>
</template>
<script>
export default function mountApp() {
    window.__phueApp = createPhueApp({});
}
</script>
PHUE);

        $parser = new PhueTemplateParser();
        $structure = $parser->parseStructure($file);

        $this->assertSame(
            [],
            $this->unresolvedBindings($file),
            'No drift is possible when nothing is bound — which is why an empty result here '
            . 'must not be read as "the page is healthy".'
        );
        $this->assertStringNotContainsString(
            '{{',
            (string) $structure['template'],
            'This fixture models the refactored-page regression: state ships, template binds '
            . 'nothing. The guard that catches it is assertNotEmpty($roots) in the caller.'
        );
    }

    public function testClientReadingAnUnshippedKeyIsDrift(): void
    {
        $file = $this->writePage(<<<'PHUE'
<php>
return [
    'title' => 'Client drift',
    'state' => [
        'testValue' => 'x',
    ],
];
</php>
<template>
<div>{{ testValue }}</div>
</template>
<script>
export default function mountApp() {
    const gone = window.__INITIAL_STATE__.removedKey;
    window.__phueApp = createPhueApp({ setup() { return { gone }; } });
}
</script>
PHUE);

        $reads = $this->clientStateReads($file);

        $this->assertSame(['removedKey'], $reads);

        foreach ($reads as $key) {
            $this->assertNotContains(
                $key,
                $this->shippedStateKeys($file),
                'The client reads a key the server never ships: __INITIAL_STATE__.removedKey.'
            );
        }
    }

    #[DataProvider('clientScriptViolations')]
    public function testClientMustDelegateMountAndHydrationToTheFactory(string $forbidden, string $because): void
    {
        $file = $this->writePage(<<<PHUE
<php>
return ['title' => 'Factory', 'state' => ['testValue' => 'x']];
</php>
<template>
<div>{{ testValue }}</div>
</template>
<script>
export default function mountApp() {
    const app = {$forbidden};
    app.mount('#app');
}
</script>
PHUE);

        $script = $this->clientScript($file);

        $this->assertStringContainsString($forbidden, $script);
        $this->assertStringContainsString(
            $forbidden,
            $script,
            $because
        );
        $this->assertStringNotContainsString(
            'createPhueApp',
            $script,
            'A page opting out of the factory has already lost the hydrate/render decision, '
            . 'so the factory contract is broken regardless of what else the script does.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function clientScriptViolations(): array
    {
        return [
            'raw createSSRApp' => [
                'createSSRApp({})',
                'createSSRApp() is the raw Vue factory. The Phue factory decides between '
                . 'createSSRApp and createApp from what the container holds.',
            ],
            'raw createApp' => [
                'createApp({})',
                'createApp() is the raw Vue factory. Calling it directly opts out of the '
                . 'hydration switch entirely.',
            ],
        ];
    }

    #[DataProvider('mountViolations')]
    public function testPageMustNotMountItself(string $forbidden, string $because): void
    {
        $file = $this->writePage(<<<PHUE
<php>
return ['title' => 'Mount', 'state' => ['testValue' => 'x']];
</php>
<template>
<div>{{ testValue }}</div>
</template>
<script>
export default function mountApp() {
    window.__phueApp = createPhueApp({});
    {$forbidden}
}
</script>
PHUE);

        $script = $this->clientScript($file);

        $this->assertStringContainsString($forbidden, $script, $because);
        $this->assertStringContainsString(
            'createPhueApp',
            $script,
            'The page went through the factory and still mounted itself — that is a double '
            . 'mount, which is exactly what the factory call is supposed to prevent.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mountViolations(): array
    {
        return [
            'self mount' => [
                "app.mount('#app');",
                'createPhueApp already mounts. A page-level .mount() mounts the app twice.',
            ],
            'vue3 has no hydrate' => [
                "app.hydrate('#app');",
                'app.hydrate() is not a Vue 3 API. Hydration happens INSIDE mount().',
            ],
        ];
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->rmTree($entry) : unlink($entry);
        }

        rmdir($dir);
    }
}