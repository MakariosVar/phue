<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Ssr;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HydrationContractTest — locks Contract 3.4 (State <-> Template binding) and
 * the Phase 4 client hydration switch on the demo pages.
 *
 * The SSR loop only closes when every layer agrees on the SAME state keys:
 *   1. the <template> bindings resolve against the state the server ships,
 *   2. the client <script> reads those same keys from __INITIAL_STATE__,
 *   3. the client adopts the server DOM via createSSRApp().mount('#app') —
 *      mounting an SSR app carries the URL and the second mount arg (hydrate)
 *      flag: in Vue 3 the hydration happens INSIDE mount(), there is no
 *      `app.hydrate()` method.
 *
 * Enforces the pre-Phase-4 drift (Home.phue shiping state['value'] while the
 * template binds {{testValue}}): such a drift makes the server interpolate an
 * EMPTY text node and the client then patch a hydration mismatch on mount.
 *
 * Pure PHP — no sockets, no Bun sidecar required.
 *
 * @package Phue\Tests\Core\Ssr
 */
#[Group('unit')]
#[Group('ssr')]
final class HydrationContractTest extends TestCase
{
    /**
     * The exact <php> params each page receives at runtime (WebController).
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function demoPages(): array
    {
        return [
            'Home' => [
                '/app/App/Frontend/Pages/Home.phue',
                ['testValue' => 'HomePage'],
            ],
            'Docs' => [
                '/app/App/Frontend/Pages/Docs.phue',
                ['doc' => 'getting-started'],
            ],
            'PhUI' => [
                '/app/App/Frontend/Pages/PhUI.phue',
                [
                    // The exact <php> state array Pages/PhUI ships, and therefore the
                    // complete server-side binding scope for its template.
                    'activeSection' => 'index',
                    'searchQuery'   => '',
                    'shellHeight'   => 'calc(100dvh - 4.75rem)',
                    'navItems'      => [
                        ['id' => 'index', 'label' => 'Overview', 'components' => []],
                        [
                            'id' => 'forms',
                            'label' => 'Form Controls',
                            'components' => [
                                ['id' => 'button', 'label' => 'Button'],
                                ['id' => 'input', 'label' => 'Input'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('demoPages')]
    public function testEverySimpleTemplateBindingHasMatchingStateKey(string $file, array $params): void
    {
        $parser = new PhueTemplateParser();
        $template = $parser->parseStructure($file)['template'];
        $stateKeys = array_keys($parser->parse($file, $params)->view->getState());

        $roots = [];

        // `{{ key }}` and `{{ obj.prop }}` → root identifier.
        preg_match_all(
            '/\{\{\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z0-9_$]+)*)\s*\}\}/',
            $template,
            $mustaches
        );
        foreach ($mustaches[1] as $expression) {
            $roots[] = explode('.', $expression)[0];
        }

        // `v-html="key"` / `v-html="obj.prop"` → root identifier.
        preg_match_all(
            '/v-html="\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z0-9_$]+)*)\s*"/',
            $template,
            $vHtml
        );
        foreach ($vHtml[1] as $expression) {
            $roots[] = explode('.', $expression)[0];
        }

        // Locals introduced by the template itself (v-for aliases / slot scopes)
        // are NOT state keys and must not be treated as binding drift.
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

$bindings = array_values(array_unique(array_diff($roots, $locals)));

        // A page whose every interpolation is a v-for alias has no state-key
        // contract to check — Pages/PhUI is exactly that case, with `{{ nav.label }}`
        // and `{{ comp.label }}` both loop aliases. Skipping it left the page with
        // the most interpolations entirely unguarded, and a rename of either alias
        // would have shipped silently. So instead of skipping, every root must
        // resolve to something real: either a state key the server ships, or a
        // local the template itself declares. A typo in either direction fails here.
        $this->assertNotEmpty(
            $roots,
            sprintf(
                '%s declares no interpolations at all. A page that ships state but binds none of '
                . 'it is either a stub or a regression — either way nothing renders from the state, '
                . 'and this contract has nothing left to verify.',
                basename($file)
            )
        );

        $unresolved = array_values(array_unique(array_diff($roots, $locals, $stateKeys)));

        $this->assertSame(
            [],
            $unresolved,
            sprintf(
                '%s binds {{ %s }}, which is neither a state key the server ships [%s] nor a '
                . 'local the template declares [%s]. Contract 3.4: template bindings must resolve '
                . 'against something, or the server interpolates an empty node and the client '
                . 'patches a hydration mismatch on mount.',
                basename($file),
                implode(' }}, {{ ', $unresolved),
                implode(', ', $stateKeys),
                implode(', ', array_unique($locals))
            )
        );
    }

    #[DataProvider('demoPages')]
    public function testClientOnlyReadsStateKeysTheServerShips(string $file, array $params): void
    {
        $parser = new PhueTemplateParser();
        $structure = $parser->parseStructure($file);
        $stateKeys = array_keys($parser->parse($file, $params)->view->getState());

        preg_match_all('/__INITIAL_STATE__\??\.([A-Za-z_$][A-Za-z0-9_$]*)/', (string) $structure['script'], $matches);
        $read = array_values(array_unique($matches[1]));

        $this->assertStringContainsString(
            '__INITIAL_STATE__',
            (string) $structure['script'],
            sprintf('%s never consumes __INITIAL_STATE__ on the client.', basename($file))
        );

        foreach ($read as $key) {
            $this->assertContains(
                $key,
                $stateKeys,
                sprintf(
                    '%s reads __INITIAL_STATE__.%s on the client, but that key is absent from server state. ',
                    basename($file),
                    $key
                )
            );
        }
    }

    #[DataProvider('demoPages')]
    public function testPageHydratesInsteadOfMounting(string $file, array $params): void
    {
        $parser = new PhueTemplateParser();
        $script = (string) $parser->parseStructure($file)['script'];

        $this->assertStringContainsString('createSSRApp', $script, sprintf('%s must use the SSR-capable app factory.', basename($file)));
        $this->assertStringContainsString('createSSRApp({', $script, sprintf('%s must instantiate the app with createSSRApp.', basename($file)));
        $this->assertStringContainsString(".mount('#app')", $script, sprintf('%s must mount the SSR app — createSSRApp().mount() hydrates the server DOM.', basename($file)));
        $this->assertStringNotContainsString('createApp(', $script, sprintf('%s must not create a fresh CSR app (Phase 4).', basename($file)));
        $this->assertStringNotContainsString(".hydrate('#app')", $script, sprintf('%s: app.hydrate() is not a Vue 3 API — hydration happens inside mount().', basename($file)));
    }
}