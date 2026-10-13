<?php

declare(strict_types=1);

namespace Phue\Tests\Suite;

use PHPUnit\Framework\TestCase;

/**
 * Layering guard: the framework names no application class.
 *
 * The defect
 * ----------
 * Two classes in src/ imported the consuming application's namespace outright:
 *
 *     src/Modules/Auth/AuthModule.php
 *         identityClass: \App\Models\User::class
 *
 *     src/Core/UI/ComponentRenderer.php
 *         use App\Config\NavigationConfig;
 *
 * Both compile. Both pass every test in this repository, because this
 * repository IS an application and has exactly those two classes. That is the
 * whole trap: the tests were green on the one codebase where the dependency
 * happens to be satisfiable.
 *
 * The cost lands on the second codebase. A `use` statement and a `::class`
 * constant are resolved at compile time against whatever happens to be in the
 * autoloader, so an application that had not put a User model at that exact
 * FQCN — which is every application that named theirs `Account`, and every
 * application that had not written one yet — did not get a missing feature. It
 * got a fatal, in a module, at runtime, for a class the application had never
 * heard of and could not have been expected to provide.
 *
 * Why this file exists when nothing else can see it
 * --------------------------------------------------
 * The behavioural tests cannot detect this. Both classes resolve to real,
 * working code here. The dependency is only observable from an application
 * that does not have App\Models\User, and no test in this repository boots
 * one, because the repository is itself that application. So the invariant is
 * pinned at the source, where it is the same size as the defect.
 *
 * Why tokenized rather than pattern-matched
 * -----------------------------------------
 * A prose mention is not a dependency. This file has to name both classes in
 * order to explain what moved, and a substring scan reads every sentence
 * explaining the fix as an instance of the thing being fixed. Comments and
 * docblocks are stripped before the scan, so a file may discuss the rule
 * freely.
 *
 * What is still allowed
 * ---------------------
 * Naming an application class as a *string* — a default to try, behind a
 * class_exists() or has() guard — is a convention, not a dependency. Nothing
 * is resolved until the class is found, and its absence is a normal answer.
 * ComponentRenderer keeps its 'App\Config\NavigationConfig' default for exactly
 * this reason. That is why these tests match the T_NAME_FULLY_QUALIFIED token
 * and not the characters of a namespace.
 *
 * @package Phue\Tests\Suite
 */
final class FrameworkApplicationIndependenceTest extends TestCase
{
    /**
     * Every PHP file under src/.
     *
     * @return list<string>
     */
    private static function frameworkFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';

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
     * Fully-qualified `App\…` references appearing in code, with their location.
     *
     * @return list<array{file: string, line: int, name: string}>
     */
    private static function applicationReferencesInCode(): array
    {
        $found = [];

        foreach (self::frameworkFiles() as $path) {
            $source = (string) file_get_contents($path);
            $tokens = token_get_all($source);

            for ($index = 0, $line = 1; $index < count($tokens); $index++) {
                $token = $tokens[$index];

                if (is_array($token)) {
                    $line = $token[2];

                    if (!in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                        continue;
                    }

                    // Both token kinds carry a leading backslash — or, for the
                    // relative form that `use App\…` produces, none at all —
                    // and a test that forgets either one passes on the very
                    // defect it was written for. Comparing against the stripped
                    // name covers \App\Models\User, App\Models\User and the
                    // `::class` on either.
                    $name = ltrim($token[1], '\\');

                    if (str_starts_with($name, 'App\\')) {
                        $found[] = [
                            'file' => $path,
                            'line' => $line,
                            'name' => $token[1],
                        ];
                    }

                    continue;
                }

                if ($token === "\n") {
                    $line++;
                }
            }
        }

        return $found;
    }

    public function testFrameworkNamesNoApplicationClass(): void
    {
        $references = self::applicationReferencesInCode();

        if ($references === []) {
            self::assertTrue(true, 'src/ holds no compile-time reference to the application namespace.');

            return;
        }

        $lines = [];

        foreach ($references as $reference) {
            $lines[] = sprintf(
                '  %s:%d  %s',
                str_replace(dirname(__DIR__, 2) . '/', '', $reference['file']),
                $reference['line'],
                $reference['name']
            );
        }

        self::fail(
            "src/ references the application's namespace from compiled code:\n"
            . implode("\n", $lines)
            . "\n\nThe framework is a library; the application is what it is installed into. A\n"
            . "fully-qualified App\\… name is resolved when the file loads, so an\n"
            . "application that named the class differently — or had not written it\n"
            . "yet — fatals in a module, for a class it was never told to provide.\n"
            . "\nBoth original sites read an application-owned class and are the right\n"
            . "shape: read it from config, or resolve it as a string behind a guard.\n"
            . "App\\Models\\User belongs in config/auth.php as identityClass;\n"
            . "App\\Config\\NavigationConfig is a default string in ComponentRenderer."
        );
    }

    /**
     * The two rules are only useful together if the convention still works.
     *
     * If a future change replaced the guarded string with a hard import, the
     * test above would pass only if this one did too — so this asserts that the
     * application's navigation class is still reached the guarded way, and that
     * the framework therefore still renders with one.
     */
    public function testApplicationNavigationIsStillReachedTheGuardedWay(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Core/UI/ComponentRenderer.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            "NAVIGATION_CONVENTION = 'App\\\\Config\\\\NavigationConfig'",
            $source,
            'The navigation convention is expected to remain a string constant. Turning '
            . 'it back into a `use` statement is the regression this guards, and the '
            . 'test above would not catch it if the application always had the class.'
        );

        self::assertStringNotContainsString(
            'use App\Config\NavigationConfig;',
            $source,
            'Core must not import an application class. A string convention behind a '
            . 'class_exists() guard degrades to "no navigation"; a `use` statement does not.'
        );
    }

    /**
     * The same rule for the Auth module, which broke a different way.
     *
     * It reached for \App\Models\User::class — no import needed, a ::class
     * constant is enough — so a grep for `use App\` alone would have missed it.
     * The identity class is the application's; config/auth.php names it.
     */
    public function testAuthIdentityClassComesFromConfigNotFromCode(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Modules/Auth/AuthModule.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString(
            "\$authConfig['identityClass'] ?? BaseUser::class",
            $source,
            'The session identity class is expected to be read from config and to fall '
            . 'back to BaseUser — the same default authResolver already uses. A literal '
            . '\App\Models\User here is what made the Auth module un-installable.'
        );

        $configPath = dirname(__DIR__, 2) . '/config/auth.php';

        if (is_file($configPath)) {
            self::assertStringContainsString(
                'identityClass',
                (string) file_get_contents($configPath),
                'config/auth.php is where an application names its identity model. If the '
                . 'key is missing there, the code above it is reading a value nothing sets.'
            );
        }
    }
}
