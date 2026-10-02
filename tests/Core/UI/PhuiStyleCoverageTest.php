<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every visual class a PhUI component emits has to resolve to something real.
 *
 * The kit has no stylesheet of its own: Bootstrap 5 is the only one, and the
 * components are Bootstrap-only by contract (PhUI_Components.md §2.2). So a
 * class that is neither a Bootstrap utility nor a native element style is dead
 * weight — it renders as if it worked and changes nothing.
 *
 * This is not hypothetical. `PhDivider` shipped `ph-divider-*` against a
 * stylesheet that never existed and was invisible; five P2 components repeated
 * the mistake in one sitting, and `PhStat` used `min-w-0`, which this Bootstrap
 * build does not ship either. None of it is visible in review.
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiStyleCoverageTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';
    private const BOOTSTRAP = __DIR__ . '/../../../storage/assets/vendor/bootstrap/bootstrap.min.css';

    /** Utilities that appear in the Bootstrap docs but not in the compiled build. */
    /**
     * Bootstrap Icons is a real font stylesheet, not the utility API: `.bi-*`
     * glyph classes come from bootstrap-icons.css and are legitimately
     * unresolvable against bootstrap.min.css.
     */
    /** The `bi` family prefix: `.bi-clipboard`, `.bi-check`, … */
    private const ICON_PREFIX = 'bi-';

    private const DOCS_ONLY = [
        'cursor', 'cursor-pointer', 'cursor-move', 'cursor-zoom-in',
        'ring', 'focus-ring', 'kbd', 'ratio', 'min-w-0', 'max-w-0',
    ];

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $path = realpath(self::BOOTSTRAP);
        $this->assertIsString($path, 'bootstrap.min.css is the only stylesheet the kit may rely on');
        $this->css = (string) file_get_contents($path);
    }

    /** @return array<string, array{string}> */
    public static function componentProvider(): array
    {
        $cases = [];
        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $cases[basename($file, '.phue')] = [$file];
        }
        return $cases;
    }

    #[DataProvider('componentProvider')]
    public function testComponentEmitsOnlyClassesThatResolve(string $file): void
    {
        $source = (string) file_get_contents($file);

        $unknown = [];
        foreach ($this->declaredClasses($source) as $class) {
            if (str_starts_with($class, 'ph-')) {
                // data-phui hooks are attributes, not classes, so anything left
                // starting with ph- here is styling with nowhere to live.
                $unknown[] = $class . ' — custom class, but the kit ships no stylesheet';
                continue;
            }

            if (str_starts_with($class, self::ICON_PREFIX)) {
                continue;
            }

            if (in_array($class, self::DOCS_ONLY, true)) {
                $unknown[] = $class . ' — docs-only utility, not in the compiled build';
                continue;
            }

            if (!preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $this->css)) {
                $unknown[] = $class;
            }
        }

        $this->assertSame([], $unknown, sprintf(
            '%s emits class(es) that resolve to nothing. A PhUI component may only use '
            . 'Bootstrap utilities and native element styles.',
            basename($file)
        ));
    }

    /**
     * Only real class bindings are inspected — a class name inside a JS string
     * is part of a snippet shown to the reader, not styling this component ships.
     *
     * @return list<string>
     */
    private function declaredClasses(string $source): array
    {
        $found = [];

        // static class="a b" only. The (?<!:) matters: a binding like
        // :class="boxClasses" names a computed, not a class list, and reading it
        // as one reported every component's class-map helper as an undefined class.
        preg_match_all('/(?<!:)\bclass="([^"{}]*)"/', $source, $static);
        foreach ($static[1] ?? [] as $chunk) {
            foreach (preg_split('/\s+/', trim($chunk)) ?: [] as $class) {
                // A binding, not a literal: a method reference or an expression
                // is resolved at render time and cannot be checked statically.
                if ($class === '' || preg_match('/[\$(){}|:?]/', $class) === 1) {
                    continue;
                }
                $found[] = $class;
            }
        }

        // 'class': 'a b'  /  "class": "a b"
        preg_match_all('/[\'"]class[\'"]\s*=>\s*[\'"]([^\'"{}$]*)[\'"]/', $source, $bound);
        foreach ($bound[1] ?? [] as $chunk) {
            foreach (preg_split('/\s+/', trim($chunk)) ?: [] as $class) {
                if ($class !== '') {
                    $found[] = $class;
                }
            }
        }

        // :class="{ 'a': cond }" — keys of a class object
        preg_match_all('/[\'":]class[\'"]?\s*=\s*\"\{([^\"]*)\}\"/', $source, $object);
        foreach ($object[1] ?? [] as $chunk) {
            preg_match_all('/[\'"]([A-Za-z][\w\s-]*?)[\'"]\s*:\s*(?:true|[^,}]+)/', $chunk, $keys);
            foreach ($keys[1] ?? [] as $key) {
                foreach (preg_split('/\s+/', trim($key)) ?: [] as $class) {
                    if ($class !== '') {
                        $found[] = $class;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
