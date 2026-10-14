<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The suite must not read the application's own source tree.
 *
 * The framework is a library. Its tests are meant to pin framework behaviour, and a
 * framework test that reaches into App/ pins the application's incidental shape instead:
 * rename a page, move a model, drop a layout, and a green suite goes red for reasons that
 * have nothing to do with the framework. The coupling hides a worse failure too — the test
 * keeps passing only because the application happens to supply a file, so a genuine
 * regression in the framework can ship behind a fixture nobody realises is load-bearing.
 *
 * What is forbidden, and why these rules
 * --------------------------------------
 * 1. `use App\...` — a compile-time dependency. The class must exist for the file to even
 *    load, so the test cannot run without an application tree.
 * 2. A path built from __DIR__ that reaches an App/ directory — the repo-root form of the
 *    same dependency, for a test that wants the real tree without saying so.
 * 3. An absolute container path (/app/App/...) — the same reach, spelled out, and worse:
 *    it only resolves inside the dev container, so the test silently cannot run elsewhere.
 *
 * What is deliberately NOT forbidden
 * ----------------------------------
 * An `App\` string used as *data* — an entity namespace a generator should write into, a
 * namespace recorded in a queue payload, a route handler recorded for caching. Those are
 * values under test, not references to load.
 *
 * A temp directory shaped like an application (`$tmp/App/Models/...`) is likewise fine,
 * and is the intended way to exercise anything that walks an App/ tree. PhiiCommandsTest
 * does exactly that: it sandboxes a populated App/ and chdir()s into it, because the
 * generator resolves its default output from getcwd(). That reproduces the behaviour under
 * guard without reading the developer's files.
 *
 * Booting an Application over the repository root is also fine, and is not what these
 * rules forbid. Core binds the component loader to `{basePath}/App/Frontend`, but that
 * binding is a lazy factory: an application test that never resolves it never reads a
 * single file under App/. The tests that boot the real base path (ApplicationTest,
 * RouterHandlerBindingTest, KernelScopeLifecycleTest, ProductionHardeningTest) exist to
 * assert how the real configuration resolves, and they carry RestoresActiveRecordConnection
 * for the database their construction rewires. Coupling to configuration is the point of
 * those tests; coupling to component source is what this file bans.
 *
 * @package Phue\Tests\Suite
 */
final class ApplicationIndependenceTest extends TestCase
{
    /**
     * Patterns anchored to statement position.
     *
     * Anchoring matters: the forbidden forms also appear as quoted search needles inside
     * other meta-tests, and those are data this file legitimately produces. Only a real
     * `use` statement at the start of a line counts.
     *
     * @var array<string, string>
     */
    private const IMPORT_RULES = [
        'imports the application namespace' => '/^[ \t]*use[ \t]+App\\\\/m',
    ];

    /**
     * Path forms that reach the repository's own App/ tree.
     *
     * @var array<string, string>
     */
    private const PATH_RULES = [
        'reaches App/ through a __DIR__ path' => '/__DIR__[^;\n]{0,48}[\'"]\/App(?:\/|[\'"])/',
        'reaches App/ through a container absolute path' => '/[\'"]\/app\/App(?:\/|[\'"])/',
    ];

    public function testNoTestImportsTheApplicationNamespace(): void
    {
        $offenders = $this->scan(self::IMPORT_RULES);

        $this->assertSame(
            [],
            $offenders,
            "The suite must not depend on the application's own classes.\n"
            . "Fix: build a fixture model under tests/<Area>/Stubs/ extending the framework base "
            . "class (BaseUser, BaseActiveRecord) and assert the framework contract against it. "
            . "A test of an App/ controller or model belongs to the application, not here — the "
            . "suite has no App/ tree to run it against.\n\nOffenders:\n - "
            . implode("\n - ", $offenders)
        );
    }

    public function testNoTestReadsTheApplicationDirectory(): void
    {
        $offenders = $this->scan(self::PATH_RULES);

        $this->assertSame(
            [],
            $offenders,
            "The suite must not read the application's App/ tree.\n"
            . "Fix: point the test at a fixture. If the code under test resolves paths from "
            . "getcwd(), build the App/ shape it expects inside a temp directory and chdir() "
            . "into it (with the cwd restored in a finally block) — that reproduces the "
            . "behaviour under guard without reading the developer's files. Hardcoded "
            . "/app/... paths are doubly wrong: they only resolve inside the dev container.\n\n"
            . "Offenders:\n - " . implode("\n - ", $offenders)
        );
    }

    /**
     * A permanent canary.
     *
     * Every rule above is a regex, and a regex that quietly stops matching is
     * indistinguishable from a rule that quietly stopped being enforced. This asserts each
     * rule still rejects the shape it exists to reject, and still accepts the App\ strings
     * the suite legitimately uses as data — against synthetic source, so it holds no
     * matter what the real corpus does.
     *
     * That canary is also what makes excluding this one file from the scan safe: the rules
     * are verified to work, they are simply not read out of the file that defines them.
     */
    public function testTheScannerStillRejectsTheShapesItForbids(): void
    {
        // Single-quoted on purpose: __DIR__ interpolates inside a double-quoted PHP
        // string, so a double-quoted sample silently becomes the real directory path and
        // stops proving anything.
        $mustReject = [
            'import' => '<?php' . "\n" . 'use App\\Models\\User;',
            'dir path' => '<?php' . "\n" . '$app = dirname(__DIR__, 3) . \'/App\';',
            'container path' => '<?php' . "\n" . '$f = \'/app/App/Frontend/Pages/Home.phue\';',
        ];

        $everyRule = [...self::IMPORT_RULES, ...self::PATH_RULES];

        foreach ($mustReject as $label => $sample) {
            $matched = array_filter(
                $everyRule,
                static fn(string $pattern): bool => preg_match($pattern, $sample) === 1
            );

            $this->assertNotEmpty(
                $matched,
                sprintf('No rule rejects a synthetic "%s" sample any more: %s', $label, $sample)
            );
        }

        $mustAccept = [
            'entity namespace as data' => '<?php' . "\n" . '$ns = \'App\\Models\';',
            'temp app tree' => '<?php' . "\n" . '$f = $this->dump . \'/App/Models/Profile.php\';',
            'search needles quoted' => '<?php' . "\n" . '$forbidden = [\'App\\Models\', \'use App\\\\\'];',
        ];

        foreach ($mustAccept as $label => $sample) {
            foreach ($everyRule as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $sample),
                    sprintf('A "%s" sample is legal but matched %s. A rule broadened past its intent.', $label, $pattern)
                );
            }
        }
    }

    /**
     * The repository itself must not grow an application again.
     *
     * Every rule above is about the suite reaching into App/. This one is about App/ having
     * anything to reach into: the framework binds its component loader to
     * `{basePath}/App/Frontend` and its Schii explorer to the app's models, so the shape is
     * still part of the contract — but the files under it are not, and they were removed
     * deliberately.
     *
     * Without this, the removal is a one-way ratchet nobody enforces. A page or a model
     * added back for convenience resurrects exactly the coupling the rest of this file
     * bans, and the first symptom is a test in this repository going quietly green because
     * the app next to it happens to supply a file.
     *
     * Empty directories are expected and ignored: they cost nothing and the bindings above
     * resolve against them whether or not they exist.
     */
    public function testTheFrameworkRepositoryShipsNoApplicationSource(): void
    {
        $appDir = dirname(__DIR__, 2) . '/App';

        $this->assertDirectoryExists(
            $appDir,
            'The App/ tree is part of the published shape; removing it changes where Core binds to.'
        );

        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $offenders[] = self::relative($file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "This repository is the framework; it ships no application.\n"
            . "App/ is where an application's controllers, models and .phue pages belong, and "
            . "the suite is forbidden from reading it (see the rules in this file). A file here "
            . "is a page or a model that belongs in the application, not here.\n\n"
            . "Offenders:\n - " . implode("\n - ", $offenders)
        );
    }

    /**
     * @param array<string, string> $rules
     * @return list<string>
     */
    private function scan(array $rules): array
    {
        $offenders = [];

        foreach (self::testFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($rules as $rule => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = sprintf(
                        '%s (%s, line %d)',
                        self::relative($file),
                        $rule,
                        self::firstLine($source, $pattern)
                    );
                }
            }
        }

        return $offenders;
    }

    private static function firstLine(string $source, string $pattern): int
    {
        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return 0;
        }

        return substr_count(substr($source, 0, (int) $matches[0][1]), "\n") + 1;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__)) + 1);
    }

    /**
     * Every PHP source under tests/, not just *Test.php — stubs and fixtures are where a
     * compile-time dependency would just as happily live.
     *
     * This file is excluded from its own scan, by identity. It necessarily spells the
     * forbidden shapes out as canary samples, and a linter that flags its own rule
     * definitions is not enforcing anything. The exclusion is one line and cannot be
     * widened to a directory or a glob, so it cannot be used to hide a real offender.
     *
     * @return list<string>
     */
    private static function testFiles(): array
    {
        $root = dirname(__DIR__);
        $self = realpath(__FILE__);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $real = $file->getRealPath();

            if ($real === false || $real === $self) {
                continue;
            }

            $files[] = $real;
        }

        sort($files);

        return $files;
    }
}