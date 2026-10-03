<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Support\TestComponentLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the composable-prelude resolution in PhueComponentLoader.
 *
 * A `.phue` component opts into shared JS helpers by declaring them in its
 * <script> block:
 *
 *     export default {
 *         composables: ['overlay', 'position'],
 *         data() { return { composables: ['overlay'] } }
 *     }
 *
 * The loader resolves those names against `src/Core/UI/js/`, concatenates the
 * files ahead of the component body, and folds a digest of the result into the
 * client and SSR fingerprints so a helper edit busts the memo.
 *
 * Every rejection path is fail-loud: an unresolvable or unsafe name must abort
 * module generation instead of shipping a module whose helpers are missing.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueComponentComposablePreludeTest extends TestCase
{
    private const DIR = __DIR__ . '/ComposablePreludeFixture';

    private string $componentsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componentsDir = self::DIR . '/views';
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }
        mkdir($this->componentsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->componentsDir)) {
            $this->rmTree($this->componentsDir);
        }
        if (is_dir(self::DIR)) {
            $this->rmTree(self::DIR);
        }

        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rmTree($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function writeComponent(string $name, string $script): void
    {
        $sfc = "<template><div>{{ label }}</div></template>\n<script>\n{$script}\n</script>\n";
        file_put_contents($this->componentsDir . '/' . $name . '.phue', $sfc);
    }

    /** Declares composables in both the module default export and data(). */
    private function scriptDeclaring(array $names): string
    {
        $list = implode(', ', array_map(static fn(string $n): string => "'{$n}'", $names));

        return "export default {\n"
             . "    composables: [{$list}],\n"
             . "    data() { return { label: 'x', composables: [{$list}] }; }\n"
             . "}\n";
    }

    /* -------------------------------------------------------------- *
     * Happy path
     * -------------------------------------------------------------- */

    public function testDeclaredComposableSourceIsPrependedToTheModule(): void
    {
        $this->writeComponent('WithOverlay', $this->scriptDeclaring(['overlay']));

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('WithOverlay');

        $this->assertIsString($module);
        $this->assertStringContainsString('function focusTrap(', $module);
        $this->assertStringContainsString('function trapTab(', $module);
        $this->assertStringContainsString('function focusableWithin(', $module);
    }

    public function testOnlyTheDeclaredComposablesAreInjected(): void
    {
        $this->writeComponent('OnlyStore', $this->scriptDeclaring(['store']));

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('OnlyStore');

        $this->assertIsString($module);
        $this->assertStringContainsString('function createToastStore(', $module);
        $this->assertStringContainsString('function createConfirmQueue(', $module);
        $this->assertStringNotContainsString('function focusTrap(', $module);
        $this->assertStringNotContainsString('function positionFor(', $module);
    }

    public function testMultipleComposablesAreInjectedInDeclarationOrder(): void
    {
        $this->writeComponent('Many', $this->scriptDeclaring(['position', 'store', 'overlay']));

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Many');

        $this->assertIsString($module);
        $this->assertStringContainsString('function positionFor(', $module);
        $this->assertStringContainsString('function createToastStore(', $module);
        $this->assertStringContainsString('function focusTrap(', $module);

        $this->assertLessThan(
            strpos($module, 'function createToastStore('),
            strpos($module, 'function positionFor('),
            'position must be injected before store'
        );
    }

    public function testRepeatedDeclarationOfTheSameHelperIsDeduplicated(): void
    {
        $this->writeComponent('Dupes', $this->scriptDeclaring(['store', 'store', 'store']));

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Dupes');

        $this->assertIsString($module);
        $this->assertSame(
            1,
            substr_count($module, 'function createToastStore('),
            'a repeated helper name must be injected exactly once'
        );
    }

    public function testComponentWithoutComposablesGetsNoPrelude(): void
    {
        $this->writeComponent('Plain', "export default { data() { return { label: 'x' }; } }\n");

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Plain');

        $this->assertIsString($module);
        $this->assertStringNotContainsString('function focusTrap(', $module);
        $this->assertStringNotContainsString('function positionFor(', $module);
        $this->assertStringNotContainsString('function createToastStore(', $module);
    }

    public function testSsrModuleAlsoReceivesThePrelude(): void
    {
        $this->writeComponent('SsrOverlay', $this->scriptDeclaring(['overlay']));

        $module = TestComponentLoader::make($this->componentsDir)->buildForSsr('SsrOverlay');

        $this->assertIsString($module);
        $this->assertStringContainsString('function focusTrap(', $module);
    }

    public function testRepeatedBuildsProduceIdenticalModules(): void
    {
        $this->writeComponent('Stable', $this->scriptDeclaring(['position']));

        $loader = TestComponentLoader::make($this->componentsDir);

        $first = $loader->buildFor('Stable');
        $second = $loader->buildFor('Stable');

        $this->assertSame($first, $second, 'prelude resolution must be deterministic');
    }

    public function testDifferentComposableSetsProduceDifferentModules(): void
    {
        $this->writeComponent('Alpha', $this->scriptDeclaring(['overlay']));
        $this->writeComponent('Beta', $this->scriptDeclaring(['position']));

        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertNotSame(
            $loader->buildFor('Alpha'),
            $loader->buildFor('Beta'),
            'a different prelude must change the module (and therefore the fingerprint)'
        );
    }

    /* -------------------------------------------------------------- *
     * Fail-loud rejection paths
     * -------------------------------------------------------------- */

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'traversal to parent' => ["'../Core/PhueComponentLoader'"],
            'traversal with slash' => ["'sub/overlay'"],
            'traversal encoded' => ["'..%2Foverlay'"],
            'absolute path' => ["'/etc/passwd'"],
            'leading dot' => ["'./overlay'"],
            'leading digit' => ["'1overlay'"],
            'leading uppercase' => ["'Overlay'"],
            'leading underscore' => ["'_overlay'"],
            'null byte' => ["\"overlay\\0\""],
            'contains dot' => ["'overlay.js'"],
            'contains space' => ["'over lay'"],
            'contains slash escape' => ["'over\\lay'"],
        ];
    }

    #[DataProvider('invalidNameProvider')]
    public function testInvalidComposableNameAbortsModuleGeneration(string $nameLiteral): void
    {
        $this->writeComponent('Evil', "export default {\n    composables: [{$nameLiteral}],\n}\n");

        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertNull(
            $loader->buildFor('Evil'),
            "an unsafe composable name ({$nameLiteral}) must abort module generation"
        );
    }

    public function testEmptyListEntriesAreIgnoredRatherThanFatal(): void
    {
        // A stray comma produces empty entries. These are authoring noise, not
        // an injection attempt, so they must be skipped instead of aborting the
        // build — while a genuinely unsafe entry alongside them still aborts.
        $this->writeComponent('Stray', $this->scriptDeclaring(['overlay']) . "\n// trailing noise\n");

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Stray');
        $this->assertIsString($module);
        $this->assertStringContainsString('function focusTrap(', $module);

        $this->writeComponent('StrayCommas', "export default {\n    composables: ['overlay', '', 'store',],\n}\n");
        $module2 = TestComponentLoader::make($this->componentsDir)->buildFor('StrayCommas');
        $this->assertIsString($module2, 'empty entries must be skipped, not fatal');
        $this->assertStringContainsString('function focusTrap(', $module2);
        $this->assertStringContainsString('function createToastStore(', $module2);

        $this->writeComponent('StrayPlusEvil', "export default {\n    composables: ['', '../evil'],\n}\n");
        $this->assertNull(
            TestComponentLoader::make($this->componentsDir)->buildFor('StrayPlusEvil'),
            'an unsafe entry must still abort the build'
        );
    }

    public function testUnknownButWellFormedComposableNameAbortsModuleGeneration(): void
    {
        $this->writeComponent('Missing', $this->scriptDeclaring(['definitely-not-shipped']));

        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertNull(
            $loader->buildFor('Missing'),
            'a helper that does not exist must abort module generation'
        );
    }

    public function testSsrBuildAlsoAbortsOnUnresolvableComposable(): void
    {
        $this->writeComponent('MissingSsr', $this->scriptDeclaring(['definitely-not-shipped']));

        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertNull($loader->buildForSsr('MissingSsr'));
    }

    public function testNameCollisionWithComponentScriptAbortsModuleGeneration(): void
    {
        // `positionFor` is exported by position.js; a component declaring that
        // binding itself would produce a redeclaration SyntaxError at runtime.
        $this->writeComponent(
            'Collides',
            "export default {\n"
            . "    composables: ['position'],\n"
            . "    mounted() { const positionFor = 1; return positionFor; }\n"
            . "}\n"
        );

        $loader = TestComponentLoader::make($this->componentsDir);

        $this->assertNull(
            $loader->buildFor('Collides'),
            'a component binding that shadows an injected export must abort module generation'
        );
    }

    public function testSimilarlyNamedBindingIsNotTreatedAsACollision(): void
    {
        // Only an exact identifier match is a collision.
        $this->writeComponent(
            'NoCollide',
            "export default {\n"
            . "    composables: ['position'],\n"
            . "    mounted() { const positionForX = 1; const myPositionFor = 2; return positionForX + myPositionFor; }\n"
            . "}\n"
        );

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('NoCollide');

        $this->assertIsString($module, 'prefixed/suffixed identifiers must not collide');
    }

    /* -------------------------------------------------------------- *
     * Marker detection must not fire inside comments or strings
     * -------------------------------------------------------------- */

    public function testDeclarationInsideALineCommentIsIgnored(): void
    {
        $this->writeComponent(
            'Commented',
            "// composables: ['overlay']\n"
            . "export default { data() { return { label: 'x' }; } }\n"
        );

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Commented');

        $this->assertIsString($module);
        $this->assertStringNotContainsString('function focusTrap(', $module);
    }

    public function testDeclarationInsideABlockCommentIsIgnored(): void
    {
        $this->writeComponent(
            'BlockCommented',
            "/*\n * composables: ['overlay']\n */\n"
            . "export default { data() { return { label: 'x' }; } }\n"
        );

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('BlockCommented');

        $this->assertIsString($module);
        $this->assertStringNotContainsString('function focusTrap(', $module);
    }

    public function testRealDeclarationSurvivesAroundComments(): void
    {
        $this->writeComponent(
            'Mixed',
            "// composables: ['position']\n"
            . "/* composables: ['store'] */\n"
            . "export default {\n"
            . "    // the real one:\n"
            . "    composables: ['overlay'],\n"
            . "    data() { return { label: 'x' }; }\n"
            . "}\n"
        );

        $module = TestComponentLoader::make($this->componentsDir)->buildFor('Mixed');

        $this->assertIsString($module);
        $this->assertStringContainsString('function focusTrap(', $module);
        $this->assertStringNotContainsString('function positionFor(', $module);
        $this->assertStringNotContainsString('function createToastStore(', $module);
    }

    /* -------------------------------------------------------------- *
     * Fingerprint / memo wiring
     * -------------------------------------------------------------- */

    public function testFingerprintCarriesAPreludeDigest(): void
    {
        $this->writeComponent('Digested', $this->scriptDeclaring(['store']));

        $loader = TestComponentLoader::make($this->componentsDir);
        $loader->buildFor('Digested');

        $fingerprints = $this->memoFingerprints($loader);

        $this->assertNotEmpty($fingerprints, 'the client memo should have recorded a fingerprint');

        $found = false;
        foreach ($fingerprints as $fingerprint) {
            // Format is `{mtime}_{size}_{contentHash}_{preludeDigest}`.
            if (substr_count((string) $fingerprint, '_') >= 3) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'the memo key must include a prelude digest segment: ' . implode(', ', $fingerprints));
    }

    public function testComponentWithoutComposablesStillHasAFingerprintSegment(): void
    {
        $this->writeComponent('NoDigest', "export default { data() { return { label: 'x' }; } }\n");

        $loader = TestComponentLoader::make($this->componentsDir);
        $loader->buildFor('NoDigest');

        $fingerprints = $this->memoFingerprints($loader);

        $this->assertNotEmpty($fingerprints);
        $this->assertStringEndsWith('_', (string) $fingerprints[0], 'the prelude digest segment is present even when empty');
    }

    /* -------------------------------------------------------------- *
     * Helpers
     * -------------------------------------------------------------- */

    /**
     * Reads the private memo keys the loader recorded, so the assertions can
     * prove the prelude digest actually reaches the cache key instead of
     * inferring it from module content.
     *
     * @return list<string>
     */
    private function memoFingerprints(object $loader): array
    {
        $property = new \ReflectionProperty($loader, 'clientMemo');

        /** @var array<string, array<string, mixed>> $memo */
        $memo = $property->getValue($loader);

        $out = [];
        foreach ($memo as $entry) {
            if (isset($entry['key'])) {
                $out[] = (string) $entry['key'];
            }
        }

        return $out;
    }
}