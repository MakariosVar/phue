<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every declaration in the queue module carries a docblock.
 *
 * This exists because it was forgotten twice. The queue module is a framework surface other people
 * read without asking the author anything — an attribute, an exception, the reason a method returns
 * a bool — and an undocumented member on it is a question nobody can answer except by reading the
 * implementation. That is fine once and not fine as a habit.
 *
 * Reading it as a test rather than a checklist is the point. A checklist is checked when there is
 * time; this fails the build.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class DocblockCoverageTest extends TestCase
{
    /**
     * The module's source directory.
     */
    private static function sourceRoot(): string
    {
        return dirname(__DIR__, 2) . '/src/Modules/Queue';
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = self::sourceRoot();
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testThereAreFilesToCheck(): void
    {
        // A guard on the guard: an empty file list would make every test below pass for the wrong
        // reason, which is the failure mode this whole file exists to prevent.
        $this->assertGreaterThan(15, count(self::sourceFiles()));
    }

    public function testEveryClassInterfaceTraitAndEnumIsDocumented(): void
    {
        $undocumented = [];

        foreach (self::sourceFiles() as $path) {
            foreach ($this->declarations($path) as $line => $signature) {
                if (str_starts_with(ltrim($signature), 'enum ')) {
                    continue;
                }

                if ($this->isDocumented($path, $line)) {
                    continue;
                }

                $undocumented[] = $this->relative($path) . ':' . $line . ' ' . $signature;
            }
        }

        $this->assertSame([], $undocumented, "Undocumented types:\n" . implode("\n", $undocumented));
    }

    public function testEveryMethodIsDocumented(): void
    {
        $undocumented = [];

        foreach (self::sourceFiles() as $path) {
            foreach ($this->methods($path) as $line => $signature) {
                if ($this->isDocumented($path, $line)) {
                    continue;
                }

                $undocumented[] = $this->relative($path) . ':' . $line . ' ' . $signature;
            }
        }

        $this->assertSame([], $undocumented, "Undocumented methods:\n" . implode("\n", $undocumented));
    }

    public function testEveryConstantIsDocumented(): void
    {
        $undocumented = [];

        foreach (self::sourceFiles() as $path) {
            foreach ($this::lines($path, '/^\s*(?:public|protected|private)\s+const\s+\w/') as $line => $signature) {
                if ($this->isDocumented($path, $line)) {
                    continue;
                }

                $undocumented[] = $this->relative($path) . ':' . $line . ' ' . $signature;
            }
        }

        $this->assertSame([], $undocumented, "Undocumented constants:\n" . implode("\n", $undocumented));
    }

    /**
     * Every property is documented, promoted constructor parameters excepted since those are
     * documented by the constructor's own @param block.
     */
    public function testEveryPropertyIsDocumented(): void
    {
        $undocumented = [];

        foreach (self::sourceFiles() as $path) {
            $lines = $this::lines($path, '/^\s*(?:public|protected|private)\s+(?:static\s+|readonly\s+)*[\w\\\\|?]+\s+\$/');

            foreach ($lines as $line => $signature) {
                if ($this->isConstructorParameter($path, $line)) {
                    continue;
                }

                if ($this->isDocumented($path, $line)) {
                    continue;
                }

                $undocumented[] = $this->relative($path) . ':' . $line . ' ' . $signature;
            }
        }

        $this->assertSame([], $undocumented, "Undocumented properties:\n" . implode("\n", $undocumented));
    }

    /**
     * A method that can fail says how.
     *
     * Scoped to the methods whose failure modes are not obvious from the signature, because
     * making every method carry a @throws would be a file of tags nobody maintains and then
     * deletes.
     */
    public function testMethodsThatCanFailSayHow(): void
    {
        $missing = [];

        foreach (self::sourceFiles() as $path) {
            $lines = self::lines($path, '/^\s*(?:public|protected)\s+(?:static\s+)*function\s+(?:push|hydrate|reserve|run|install)\s*\(/');

            foreach ($lines as $line => $signature) {
                $doc = $this->docblockAbove($path, $line) ?? '';

                if (str_contains($doc, '@throws') || str_contains($doc, '@return')) {
                    continue;
                }

                $missing[] = $this->relative($path) . ':' . $line . ' ' . $signature;
            }
        }

        $this->assertSame([], $missing, "Methods that can fail without saying how:\n" . implode("\n", $missing));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Line number to declaration text, for types.
     *
     * @return array<int, string>
     */
    private function declarations(string $path): array
    {
        return $this::lines($path, '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+\w/');
    }

    /**
     * Line number to signature, for methods.
     *
     * @return array<int, string>
     */
    private function methods(string $path): array
    {
        return $this::lines($path, '/^\s*(?:public|protected|private)\s+(?:static\s+)*function\s+\w/');
    }

    /**
     * @return array<int, string>
     */
    private static function lines(string $path, string $pattern): array
    {
        $found = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $found[$index + 1] = trim($line);
            }
        }

        return $found;
    }

    /**
     * Whether the declaration on this line is preceded by a docblock.
     *
     * Walked upwards over attributes and blank lines, because a member may carry attributes
     * between its docblock and its declaration and a check that does not allow for them reports
     * perfectly documented code as undocumented.
     */
    private function isDocumented(string $path, int $line): bool
    {
        return $this->docblockAbove($path, $line) !== null;
    }

    /**
     * The docblock attached to the declaration on this line, or null when there is none.
     *
     * Walks upwards over attributes and blank lines, because a member may carry attributes between
     * its docblock and its declaration, and a reader that does not allow for them reports perfectly
     * documented code as undocumented. Returns the whole block so callers can look inside it.
     */
    private function docblockAbove(string $path, int $line): ?string
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        for ($index = $line - 2; $index >= 0; $index--) {
            $text = trim($lines[$index]);

            if ($text === '' || str_starts_with($text, '#[')) {
                continue;
            }

            if (!str_ends_with($text, '*/')) {
                return null;
            }

            $start = $index;

            while ($start >= 0 && !str_starts_with(trim($lines[$start]), '/**')) {
                $start--;
            }

            if ($start < 0) {
                return null;
            }

            return implode("\n", array_slice($lines, $start, $index - $start + 1));
        }

        return null;
    }

    /**
     * Whether this line is a promoted constructor parameter rather than a property declaration.
     */
    private function isConstructorParameter(string $path, int $line): bool
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        // A promoted parameter can only appear in a constructor's parameter list, and that list can
        // be longer than any fixed window — a twenty-line signature is normal here. The walk stops
        // at the end of the previous member so a property declared after a method is never
        // mistaken for a promoted parameter of a constructor further up.
        for ($index = $line - 1; $index >= 0; $index--) {
            $text = trim($lines[$index]);

            if (str_contains($text, 'function __construct')) {
                return true;
            }

            if ($text === '}' || $text === ');') {
                return false;
            }
        }

        return false;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $path);
    }
}