<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A showcase snippet must be the demo it documents.
 *
 * The snippet is the copy-paste contract: it is what a reader takes away. When
 * it drifts from the demo above it, the demo is the thing that lies — the
 * snippet shows a prop that does not exist, or omits the one the demo depends
 * on, and nothing in the page says so. Several showcase snippets were stale
 * copy-paste from a different component before this check existed.
 *
 * Comparison is whitespace-insensitive on the demo side: an example indents its
 * markup for readability, while the snippet has to start at column 0 to read as
 * something you would paste.
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiShowcaseSnippetTest extends TestCase
{
    private const SHOWCASE = __DIR__ . '/../../../App/Frontend/Components/PhUI/showcase';

    /** @return array<string, array{string}> */
    public static function exampleProvider(): array
    {
        $cases = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SHOWCASE, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.phue')) {
                $cases[$file->getFilename()] = [$file->getPathname()];
            }
        }
        return $cases;
    }

    #[DataProvider('exampleProvider')]
    public function testSnippetCoversTheDemoMarkup(string $file): void
    {
        $source = (string) file_get_contents($file);

        $demo = $this->extractDemoBody($source);
        if ($demo === null) {
            $this->markTestSkipped('no #demo slot');
        }

        if (preg_match('/snippet:\s*`([^`]*)`/s', $source, $snippet) !== 1) {
            $this->fail('the example has a #demo slot but no `snippet` string');
        }

        $snippet = $snippet[1];
        $this->assertNotSame('', trim($snippet), 'snippet is empty');

        // Every component tag used in the demo must appear in the snippet.
        $missing = [];
        foreach ($this->componentTags($demo) as $tag) {
            if (!str_contains($snippet, '<' . $tag)) {
                $missing[] = '<' . $tag . '>';
            }
        }

        $this->assertSame([], $missing, sprintf(
            "%s: the demo uses %s but the snippet never shows %s. A snippet is the "
            . "copy-paste contract — if it is a different example, it is wrong.",
            basename($file),
            implode(', ', array_slice($this->componentTags($demo), 0, 4)),
            implode(', ', $missing)
        ));
    }

    /** The markup inside <template #demo>, minus the example-factory's own card. */
    private function extractDemoBody(string $source): ?string
    {
        if (preg_match('/<template #demo>(.*?)\n\s*<\/template>/s', $source, $match) !== 1) {
            return null;
        }

        $body = $match[1];
        // The ph-card wrapper is the example factory's chrome (it hosts the
        // Code/Props toggles), not part of the component usage being shown.
        $body = preg_replace('/<ph-card[^>]*>/', '', $body, 1) ?? $body;
        $body = preg_replace('/<\/ph-card>/', '', $body, 1) ?? $body;

        return trim($body);
    }

    /**
     * The distinct PhUI tags in the demo body, so a snippet is required to show
     * every one of them rather than merely overlapping.
     *
     * @return list<string>
     */
    private function componentTags(string $body): array
    {
        preg_match_all('/<(ph-[a-z0-9-]+)/i', $body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
