<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards on the test suite itself.
 *
 * A misfiled test class is not a cosmetic problem. composer.json maps
 * `Phue\Tests\` to `tests/` with PSR-4, so a class whose namespace disagrees
 * with its directory is invisible to the autoloader. Nothing fails loudly:
 * class_exists() quietly returns false, a meta-test that reflects over the
 * suite skips the file, and the coverage you thought you had is not there.
 *
 * The failure surfaces much later as "Cannot redeclare class" the day someone
 * creates a file at the path the class name actually claims. Both guards below
 * exist to move that failure to the moment it is introduced.
 */
final class TestLayoutTest extends TestCase
{
    private static function testsRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return list<string>
     */
    private static function testFiles(): array
    {
        $root = self::testsRoot();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
                $path = $file->getRealPath();
                if ($path !== false) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every class/trait/interface declared in a test file, keyed by FQCN.
     *
     * @return array<string, list<string>>
     */
    private static function declaredSymbols(): array
    {
        $symbols = [];

        foreach (self::testFiles() as $path) {
            $source = (string) file_get_contents($path);
            $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1
                ? trim($m[1], '\\')
                : '';

            preg_match_all(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(class|trait|interface|enum)\s+(\w+)/m',
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $fqn = ($namespace !== '' ? $namespace . '\\' : '') . $match[2];
                $symbols[$fqn][] = self::relative($path);
            }
        }

        ksort($symbols);

        return $symbols;
    }

    public function testEveryTestFileNamespaceMatchesItsPsr4Path(): void
    {
        $root = self::testsRoot();
        $mismatches = [];

        foreach (self::testFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
                $mismatches[] = self::relative($path) . ' declares no namespace';
                continue;
            }

            $directory = str_replace(
                '/',
                '\\',
                trim(substr(dirname($path), strlen($root) + 1), '/')
            );

            $expected = rtrim('Phue\\Tests\\' . ($directory === '' ? '' : $directory . '\\'), '\\');
            $declared = trim($matches[1], '\\');

            if (strcasecmp($expected, $declared) !== 0) {
                $mismatches[] = sprintf(
                    '%s expects namespace %s but declares %s — the autoloader will never find it',
                    self::relative($path),
                    $expected,
                    $declared
                );
            }
        }

        $this->assertSame([], $mismatches, implode("\n", $mismatches));
    }

    /**
     * PHP symbols are global once loaded. Two files declaring the same FQCN
     * means the second include() is a fatal, and it is usually masked until a
     * meta-test autoloads the whole suite at once.
     */
    public function testNoSymbolIsDeclaredInTwoTestFiles(): void
    {
        $collisions = [];

        foreach (self::declaredSymbols() as $fqn => $paths) {
            if (count($paths) > 1) {
                sort($paths);
                $collisions[] = $fqn . ' in ' . implode(', ', $paths);
            }
        }

        $this->assertSame(
            [],
            $collisions,
            "These symbols are declared in more than one test file. Loading both is a fatal "
            . "error.\n" . implode("\n", $collisions)
        );
    }

    /**
     * Test fixtures are named for readability, not uniqueness, and two files
     * declaring `class User` in the same namespace is the collision above in
     * slow motion. Across different namespaces it is merely confusing, so this
     * only reports it — the hard failure is the previous test.
     */
    public function testFixtureClassNamesAreNotReusedAcrossNamespaces(): void
    {
        $byShortName = [];

        foreach (self::declaredSymbols() as $fqn => $paths) {
            if (str_ends_with($fqn, 'Test')) {
                continue;
            }

            $short = substr($fqn, (int) strrpos($fqn, '\\') + 1);
            $byShortName[$short][] = $fqn;
        }

        $reused = [];

        foreach ($byShortName as $short => $fqns) {
            if (count($fqns) > 1) {
                sort($fqns);
                $reused[] = $short . ': ' . implode(', ', $fqns);
            }
        }

        $this->assertSame(
            [],
            $reused,
            "These fixture class names appear in more than one namespace. Not fatal today, but "
            . "a namespace merge or a directory move turns it into one.\n" . implode("\n", $reused)
        );
    }

    private static function relative(string $path): string
    {
        return (string) preg_replace(
            '#^' . preg_quote(self::testsRoot(), '#') . '/#',
            '',
            $path
        );
    }
}
