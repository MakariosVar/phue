<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhuiTemplateBindingTest — every identifier a PhUI template binds must exist.
 *
 * Vue does not fail on an undeclared property. It renders `undefined` and logs
 * "[Vue warn]: Property X was accessed during render but is not defined on
 * instance" — which is invisible in a screenshot and only shows up in the
 * browser console. That is how `PhFileInput` shipped a `:aria-label` bound to a
 * `placeholder` prop it never declared, and a template referencing a `hint` that
 * did not exist: both render fine, both are broken.
 *
 * This walks the real component sources, extracts the identifiers each template
 * binds, and asserts every one of them resolves to a declared prop, a
 * computed/data/method member, or a known global. It is a static check on
 * purpose: catching it here costs nothing, and catching it from a console
 * warning in production costs a user report.
 *
 * Scope is deliberately narrow — attribute-value expressions only. Slot
 * content and the <script> block are not analysed, so the assertion is precise
 * rather than a heuristic.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhuiTemplateBindingTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    /**
     * Identifiers a template may legitimately reference.
     *
     * @var array<int, string>
     */
    private const GLOBALS = [
        'true', 'false', 'null', 'undefined', 'Math', 'JSON', 'Object', 'Array',
        'String', 'Number', 'Boolean', 'Date', 'console', 'window', 'document',
        'Vue', 'useId', 'ref', 'computed', 'reactive', 'watch', 'nextTick',
    ];

    public function testEveryBoundIdentifierResolvesToSomethingDeclared(): void
    {
        $offenders = [];

        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $name = basename($file, '.phue');
            $source = (string) file_get_contents($file);

            $declared = $this->declaredIdentifiers($source);
            $used = $this->boundIdentifiers($source);

            foreach ($used as $identifier) {
                if (in_array($identifier, $declared, true) || in_array($identifier, self::GLOBALS, true)) {
                    continue;
                }

                $offenders[] = "{$name}: {$identifier}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "templates bind identifiers that are neither a declared prop/member nor a known global:\n  "
            . implode("\n  ", $offenders)
            . "\n\nVue only logs a console warning for these; they render as undefined."
        );
    }

    /**
     * Every object-member key declared anywhere in the <script> block.
     *
     * One rule instead of per-block regexes: props are `name: {`, computed and
     * methods are `name() {`, data() and setup() return nested `name:` keys.
     * They differ only in indentation and in whether they are followed by a
     * colon or a parenthesis, so a single "start of line, at least two spaces
     * deep, identifier, then : or (" pattern collects all of them. The result is
     * a superset (it also picks up keys inside helper objects), which is the
     * safe direction: over-collecting can hide a real offender, under-collecting
     * would bury the test in false positives.
     *
     * @return array<int, string>
     */
    private function declaredIdentifiers(string $source): array
    {
        if (!preg_match('/<script>(.*)<\/script>/s', $source, $m)) {
            return [];
        }

        preg_match_all('/^\s{2,}([a-zA-Z_$][\w$]*)\s*[:(]/m', $m[1], $k);
        $names = $k[1];

        // `const isRouteActive = (href) => {}` inside setup()
        preg_match_all('/^\s*const\s+([a-zA-Z_$][\w$]*)\s*=/m', $m[1], $c);
        $names = array_merge($names, $c[1]);

        // shorthand return members: a line that is just `name,`
        preg_match_all('/^\s{2,}([a-zA-Z_$][\w$]*)\s*,\s*$/m', $m[1], $sh);
        $names = array_merge($names, $sh[1]);

        return array_values(array_unique($names));
    }

    /**
     * Identifiers referenced from attribute-value expressions and mustaches.
     *
     * @return array<int, string>
     */
    private function boundIdentifiers(string $source): array
    {
        $template = $this->templateOf($source);
        $found = [];

        // v-for aliases and slot-scope destructuring are local, not members
        $aliases = [];

        // <template #trigger="{ open, toggle }">
        if (preg_match_all('/#\w+="\{([^}]*)\}"(?!\s*:)/', $template, $slots)) {
            foreach ($slots[1] as $list) {
                foreach (explode(',', $list) as $alias) {
                    $alias = trim($alias);
                    if (preg_match('/^([a-zA-Z_$][\w$]*)$/', $alias, $mm)) {
                        $aliases[] = $mm[1];
                    }
                }
            }
        }

        if (preg_match_all('/v-for="\(?([\w,\s$]+)\)?\s+(?:in|of)\s/', $template, $m)) {
            foreach ($m[1] as $list) {
                foreach (explode(',', $list) as $alias) {
                    $alias = trim($alias);
                    if ($alias !== '') {
                        $aliases[] = $alias;
                    }
                }
            }
        }

        // Only aria-* bindings. A full expression analyser is not viable here:
        // inline :style objects contribute CSS property names, v-for contributes
        // loop aliases, and arrow functions contribute their own parameters —
        // 163 false positives, none of them actionable. The aria-* surface is
        // small, precise, and is where an undeclared name silently removes an
        // accessible name, which is the regression this guards.
        if (preg_match_all('/\s:aria-[\w-]+="([^"]*)"/', $template, $m)) {
            foreach ($m[1] as $expr) {
                $found = array_merge($found, $this->identifiersIn($expr));
            }
        }

        return array_values(array_diff(array_unique($found), $aliases));
    }

    private function templateOf(string $source): string
    {
        if (preg_match('/<template>(.*)<\/template>/s', $source, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function identifiersIn(string $expr): array
    {
        // drop string literals so their contents are not mistaken for identifiers
        $expr = preg_replace("/'[^']*'|\"[^\"]*\"|`[^`]*`/", ' ', $expr) ?? $expr;

        preg_match_all('/(?<![.\w$])([a-zA-Z_$][\w$]*)/', $expr, $m);

        // keywords used inside expressions
        $skip = ['true', 'false', 'null', 'undefined', 'typeof', 'in', 'of', 'new', 'this'];

        return array_values(array_filter(
            array_unique($m[1]),
            static fn(string $id): bool => !in_array($id, $skip, true)
        ));
    }
}
