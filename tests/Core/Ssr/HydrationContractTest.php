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
            'UI' => [
                '/app/App/Frontend/Pages/UI.phue',
                [
                    'checked'        => true,
                    'radioValue'     => 'option1',
                    'loading'        => false,
                    'currentPage'    => 3,
                    'totalPages'     => 10,
                    'activeTab'      => 'tab1',
                    'modalOpen'      => false,
                    'alertDismissed' => false,
                    'inputValue'     => 'Sample text',
                    'selectValue'    => 'option2',
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

        $this->assertNotEmpty($bindings, sprintf('%s exposes no template bindings to check.', basename($file)));

        foreach ($bindings as $binding) {
            $this->assertContains(
                $binding,
                $stateKeys,
                sprintf(
                    '%s binds "{{ %s }}" but the server state only ships: [%s]. '
                    . 'Contract 3.4: template binding names must equal state keys '
                    . 'so the server and the client resolve the same value.',
                    basename($file),
                    $binding,
                    implode(', ', $stateKeys)
                )
            );
        }
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