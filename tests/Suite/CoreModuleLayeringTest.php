<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use PHPUnit\Framework\TestCase;

/**
 * Layering guard: the persistence layer names no module.
 *
 * The defect
 * ----------
 * BaseActiveRecord::getAutoCasts() resolved properties by reading
 *
 *     \Phue\Modules\Schii\Attribute\Field::class
 *
 * out of Core. Modules depend on Core, never the reverse, and the cost was not
 * architectural tidiness — it was silent data corruption. A module is
 * optional. In an application whose template does not install the admin
 * module, that class is absent, no property matches, the auto-cast map comes
 * back empty, and every typed property crosses the driver boundary uncast. A
 * `#[Field(type: 'json')] public array $roles` is stored as the literal string
 * "Array" and read back as the string "Array". No exception, no warning, no
 * failed test: a correct-looking model writing rows of the wrong shape.
 *
 * Why this file exists when FieldAttributeCastingTest already covers the
 * behaviour
 * --------------------------------------------------------------------
 * Because the behavioural test cannot see this on its own. An application
 * that DOES install the admin module behaves identically whichever namespace
 * the attribute lives in — which is every test in this suite. The dependency
 * is only observable in the application that does not have the module, and no
 * test in this repository boots one that lacks it. So the invariant is pinned
 * at the source instead, where it is the same size as the defect.
 *
 * Why the scope is src/Core/Database/ and not all of src/Core/
 * --------------------------------------------------------------
 * Other Core files name modules for reasons this invariant does not condemn:
 * Application consults AuthManager behind a `has()` guard, OptimizeCommand
 * knows the schema registry, and the project-scaffolding commands ship the
 * code generator. Those are Core choosing to integrate with an optional
 * module, which is legitimate. What is not legitimate is a module CLASS
 * choosing the shape of Core's persistence, because persistence has to hold
 * for the application that installed nothing. Widening this file to all of
 * src/Core/ would fail today on deliberate, reviewed integrations and train
 * everyone to ignore it — the failure mode of every ratchet that starts
 * aspirational. The scope is the layer where the corruption happened; if
 * another layer grows a hard dependency, that gets its own deliberate test.
 */
final class CoreModuleLayeringTest extends TestCase
{
    private static function sourceRoot(): string
    {
        return dirname(__DIR__, 2) . '/src/Core/Database';
    }

    /**
     * Every PHP file under src/Core/Database/.
     *
     * @return list<string>
     */
    private static function persistenceFiles(): array
    {
        $root = self::sourceRoot();

        if (!is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            if ($path !== false) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `Phue\Modules\` references in CODE, with the line they appear on.
     *
     * Tokenized rather than pattern-matched, because a prose mention is not a
     * dependency. This class's own file has to say "presentation lives in
     * \Phue\Modules\Schii\Attribute\Column" in order to explain where it went,
     * and a substring scan reads that sentence as the violation it is
     * forbidding. The same trap already cost the suite one guard in
     * KnownVulnerabilityRatchetTest, where an all-caps defect marker written
     * in prose was indistinguishable from a real one.
     *
     * Both spellings are caught, because an inline fully-qualified reference
     * bypasses the import block entirely — which is how the original defect was
     * written. Comments are skipped; strings are not, since a module class name
     * reaches the token stream as a name token rather than inside a literal in
     * every case that matters.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    private static function moduleReferences(): array
    {
        $found = [];

        foreach (self::persistenceFiles() as $path) {
            $tokens = token_get_all((string) file_get_contents($path));

            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    continue;
                }

                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                if (!str_contains($token[1], 'Phue\\Modules\\')) {
                    continue;
                }

                $found[] = [
                    'file' => $path,
                    'line' => $token[2],
                    'text' => trim($token[1]),
                ];

                unset($index);
            }
        }

        return $found;
    }

    public function testPersistenceLayerContainsPhpFilesToCheck(): void
    {
        $this->assertNotEmpty(
            self::persistenceFiles(),
            'The scan found no PHP files under src/Core/Database/, so the layering guard below would pass vacuously. A moved directory must not turn this file into a no-op.',
        );
    }

    public function testPersistenceLayerNamesNoModule(): void
    {
        $references = self::moduleReferences();

        $this->assertSame(
            [],
            $references,
            sprintf(
                "Core persistence names a module in %d place(s). Modules depend on Core, not the reverse.\n"
                . "A module is optional, so a hard reference from here means an application that does not\n"
                . "install it loses behaviour silently instead of failing loudly. Move the contract the\n"
                . "persistence layer needs into Core, and let the module read it from there.\n\n%s",
                count($references),
                implode("\n", array_map(
                    static fn (array $r): string => sprintf(
                        '  %s:%d  %s',
                        str_replace(dirname(__DIR__, 2) . '/', '', $r['file']),
                        $r['line'],
                        $r['text'],
                    ),
                    array_slice($references, 0, 10),
                )),
            ),
        );
    }
}