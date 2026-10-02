<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhuiSsrRenderGateTest — the showcase page must server-render, not degrade.
 *
 * When a component throws during SSR the whole page render fails, and the shell
 * falls back to injecting the page's **raw template source** into `#app`. The
 * response is a 200, the HTML validates, every existing test is green — and the
 * page ships with its Vue directives written out as text (`v-if="…"`, `@click="…"`,
 * `:style="…"`) and no content at all.
 *
 * That is exactly what happened: `<ph-rich-text>` computed a name that collided
 * with one of its own props, its template read `toolbarItems.length` against a
 * `null` default, and the server render threw. `PhuiComponentBuildTest` passed
 * (the module builds), `PhuiStyleCoverageTest` passed (the classes resolve),
 * `PhuiTemplateBindingTest` passed (it only reads `aria-*` bindings). The symptom
 * was a hydration mismatch reported from a browser console, long after the damage.
 *
 * So this gate asserts the rendered result, not the source: the showcase page's SSR
 * output has to contain its sidebar and its section markup, and must not contain
 * its own directives.
 *
 * Requirements (skipped, never failed, when absent):
 *  - `bun` on PATH (the client runtime is executed with it),
 *  - a Phue server reachable on PhueSsrBaseUrl (defaults to 127.0.0.1:8000).
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('integration')]
final class PhuiSsrRenderGateTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:8000';
    private const PAGE = '/phui';

    private static ?string $body = null;

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $bun = trim((string) shell_exec('command -v bun 2>/dev/null'));
        if ($bun === '') {
            self::$skipReason = 'bun is unavailable.';

            return;
        }

        $url = rtrim((string) (getenv('PhueSsrBaseUrl') ?: self::BASE_URL), '/') . self::PAGE;
        $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);

        if (!is_string($body) || $body === '') {
            self::$skipReason = 'no Phue server at ' . $url;

            return;
        }

        self::$body = $body;
    }

    private function body(): string
    {
        if (self::$body === null) {
            self::markTestSkipped(self::$skipReason);
        }

        return self::$body;
    }

    /** The markup the shell injected into the mount container. */
    private function appInnerHtml(): string
    {
        $body = $this->body();
        $start = strpos($body, 'id="app"');

        self::assertIsInt($start, 'the page has no #app mount container');
        $start += strlen('id="app">');

        $end = strpos($body, '</div>', $start);

        return $end === false ? substr($body, $start) : substr($body, $start, $end - $start);
    }

    public function testThePageDidNotFallBackToItsOwnSource(): void
    {
        $inner = $this->appInnerHtml();

        // The signature of the degraded path: the template's own text, verbatim.
        foreach (['v-if=', '@click=', ':style=', 'ref="shellEl"', '{ height:'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $inner,
                sprintf(
                    'The showcase page rendered its OWN TEMPLATE SOURCE into #app (found %s). '
                    . 'A component threw during SSR and the shell fell back to the raw markup. '
                    . 'The response is still a 200 and every other gate is green — look for a '
                    . 'component that throws when its template first evaluates.',
                    $needle
                )
            );
        }
    }

    public function testTheServerRenderedRealMarkup(): void
    {
        $inner = $this->appInnerHtml();

        self::assertStringContainsString('class="d-flex overflow-hidden"', $inner, 'the shell root is missing');
        self::assertStringContainsString('id="phue-phui-sidebar"', $inner, 'the sidebar did not render');
        self::assertStringContainsString('calc(100dvh - 4.75rem)', $inner, 'the shell height state was not applied');

        // `ssr: false` components become empty placeholders, which is correct: their
        // client-only markup must NOT be in the server output.
        self::assertStringNotContainsString('<ph-toast', $inner);
        self::assertStringNotContainsString('<ph-confirm-dialog', $inner);
    }

    public function testTheServerSendsTheStateTheClientHydratesAgainst(): void
    {
        $body = $this->body();

        self::assertStringContainsString(
            'window.__INITIAL_STATE__',
            $body,
            'the page state is not serialised, so the client hydrates against an empty tree'
        );
    }
}