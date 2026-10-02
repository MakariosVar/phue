<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
/**
 * PhuiShadowingContractTest — a computed or method may not take a prop's name.
 *
 * Vue's render proxy resolves props FIRST, and silently: defining
 * `computed: { toolbarItems() }` next to `props: { toolbarItems }` does not
 * override the prop, it just creates a computed that the template and `this` can
 * never reach. Nothing warns.
 *
 * It is only invisible until it is not. `<ph-rich-text>` declared exactly that
 * pair and its template read `toolbarItems.length` to decide whether to render the
 * toolbar — against the prop, whose default is `null`. The component threw
 * `can't access property "length", _ctx.toolbarItems is null` on every render,
 * including SSR, and every static gate in the suite was green: the module built,
 * the classes resolved, the props were "declared", and `PhuiTemplateBindingTest`
 * does not look at `v-if` expressions.
 *
 * The two failure shapes are different and both are locked here:
 *  - a same-named member declared at all (the hazard), and
 *  - a prop whose default is `null`/`undefined` being read with a member operator
 *    in the template (the shape that turns the hazard into a crash).
 *
 * @package Phue\Tests\Core\UI
 */
final class PhuiShadowingContractTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    public function testNoComputedOrMethodShadowsAProp(): void
    {
        $offenders = [];

        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $name = basename($file, '.phue');
            $script = (new PhueTemplateParser())->parseStructure($file)['script'] ?? null;
            if ($script === null) {
                continue;
            }

            $props = $this->blockMembers($script, 'props');
            $computed = $this->blockMembers($script, 'computed');
            $methods = $this->blockMembers($script, 'methods');

            foreach (array_keys($props) as $prop) {
                if (isset($computed[$prop])) {
                    $offenders[] = "{$name}: computed `{$prop}` is shadowed by the prop of the same name";
                }
                if (isset($methods[$prop])) {
                    $offenders[] = "{$name}: method `{$prop}` is shadowed by the prop of the same name";
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    public function testAPropWithNoDefaultIsNotDereferencedInTheTemplate(): void
    {
        $offenders = [];

        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $name = basename($file, '.phue');
            $structure = (new PhueTemplateParser())->parseStructure($file);
            $script = $structure['script'] ?? null;
            $template = $structure['template'] ?? '';

            if ($script === null || $template === '') {
                continue;
            }

            foreach ($this->nullableProps($script) as $prop) {
                // `<prop>.something` or `<prop>[0]` outside a binding expression:
                // the readable case. Inside `:x="…"` the binding tests have it.
                $pattern = '/(?<![\w$.\'"-])' . preg_quote($prop, '/') . '\s*[.[]/';
                if (preg_match_all($pattern, $template, $matches)) {
                    $offenders[] = sprintf(
                        '%s: `%s` has no default (so it can be %s) and the template dereferences it %d×',
                        $name,
                        $prop,
                        'null/undefined',
                        count($matches[0])
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * Direct member names of a top-level options block (`props: {`, `computed: {`…).
     *
     * @return array<string, true>
     */
    private function blockMembers(string $script, string $block): array
    {
        if (preg_match('/^\s{2}' . $block . ':\s*\{/m', $script, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        $length = strlen($script);

        for ($i = $start; $i < $length && $depth > 0; $i++) {
            if ($script[$i] === '{') {
                $depth++;
            } elseif ($script[$i] === '}') {
                $depth--;
            }
        }

        $body = substr($script, $start, $i - $start);
        $members = [];

        // Direct children only: exactly two-space indentation, one level in.
        if (preg_match_all('/^ {4}([a-zA-Z_$][\w$]*)\s*[:(]/m', $body, $matches)) {
            foreach ($matches[1] as $member) {
                $members[$member] = true;
            }
        }

        return $members;
    }

    /**
     * Props whose default is absent, or literally null/undefined.
     *
     * @return list<string>
     */
    private function nullableProps(string $script): array
    {
        if (preg_match('/^\s{2}props:\s*\{/m', $script, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        $length = strlen($script);

        for ($i = $start; $i < $length && $depth > 0; $i++) {
            if ($script[$i] === '{') {
                $depth++;
            } elseif ($script[$i] === '}') {
                $depth--;
            }
        }

        $body = substr($script, $start, $i - $start);
        $nullable = [];

        // Split the props block into its `name: { … },` entries, then look at each.
        if (preg_match_all('/^ {4}([a-zA-Z_$][\w$]*):\s*\{/m', $body, $entries, PREG_OFFSET_CAPTURE)) {
            foreach ($entries[1] as $index => $match) {
                // PREG_OFFSET_CAPTURE turns every group into [value, offset].
                $member = $match[0];
                $entryStart = $match[1];
                // Only the text between this entry's header and the next one matters.
                $entryEnd = $entries[0][$index + 1][1] ?? strlen($body);
                $entry = substr($body, $entryStart, $entryEnd - $entryStart);

                // `default:` anywhere in the entry, not only at line start: single-line
                // declarations (`items: { type: Array, default: () => [] }`) are the
                // common shape and reading only the indented form reports them as
                // having no default at all.
                $hasDefault = preg_match('/\bdefault\s*:/', $entry) === 1;
                $defaultIsNull = preg_match('/\bdefault\s*:\s*(null|undefined)\b/', $entry) === 1;
                // A `required` prop with no default is an explicit contract, not an
                // oversight: the caller is told, at their call site, that it is missing.
                $isRequired = preg_match('/\brequired\s*:\s*true/', $entry) === 1;

                if ((!$hasDefault && !$isRequired) || $defaultIsNull) {
                    $nullable[] = $member;
                }
            }
        }

        return $nullable;
    }
}