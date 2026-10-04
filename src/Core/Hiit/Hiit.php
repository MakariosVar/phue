<?php

declare(strict_types=1);

namespace Phue\Core\Hiit;

/**
 * Reports which components no longer match what a caller recorded.
 *
 * The component loader has to decide one thing at the start of every request: is what it
 * memoised still true? Answering "no, forget everything" is always safe and costs a full
 * rebuild — around 90ms on a component-heavy page, paid whether or not a single file moved.
 * Answering "yes, still true" costs a directory scan and one content hash per component,
 * roughly 10ms, and lets every memo survive the request boundary.
 *
 * The check has to be a content hash and never an mtime. An edit that lands in the same
 * second and happens to keep the byte count is invisible to a stat, and it is exactly what
 * a developer produces by retyping a word in a template and refreshing without thinking
 * about timestamps. PhueComponentFreshnessTest pins that such an edit must be served.
 *
 * Deliberately a pure function of its arguments. There is no watcher to supervise, no timer
 * to keep coherent and no state that can go stale between the moment a change happens and
 * the moment somebody reads the answer — which is what makes it testable without any
 * doubles and safe to call from a request path.
 *
 * @package Phue\Core\Hiit
 */
final class Hiit
{
    /**
     * Names reported by the most recent changed() call.
     *
     * Kept so a debugging surface can show what was found without re-scanning the disk on
     * every render.
     *
     * @var list<string>
     */
    private array $lastChanged = [];

    /**
     * What the last comparison saw, by component name.
     *
     * @var array<string, string>
     */
    private array $current = [];

    /**
     * @param string $componentsDir Base directory holding .phue components.
     */
    public function __construct(private readonly string $componentsDir)
    {
    }

    /**
     * Which components differ from what the caller last recorded.
     *
     * A name is reported when its content hash moved, when it appeared on disk, or when it
     * disappeared from it — the last one matters as much as the first, because a memo
     * entry for a deleted file has nothing left on disk to contradict it.
     *
     * @param array<string, string> $known Component name => content hash at the time of
     *        the caller's last look. Names absent from it count as added.
     *
     * @return list<string> Sorted, so the answer is stable and diffable.
     */
    public function changed(array $known): array
    {
        $current = $this->currentHashes();
        $this->current = $current;
        $changed = [];

        foreach ($current as $name => $hash) {
            if (!array_key_exists($name, $known) || !hash_equals($known[$name], $hash)) {
                $changed[] = $name;
            }
        }

        foreach ($known as $name => $hash) {
            if (!array_key_exists($name, $current)) {
                $changed[] = (string) $name;
            }
        }

        sort($changed);

        return $this->lastChanged = $changed;
    }

    /**
     * The hashes the most recent changed() call compared against.
     *
     * A caller comparing on a schedule needs this as the baseline for its next comparison.
     * Without it the only way to get one is currentHashes(), which rescans — so every poll would
     * read every component file twice.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        return $this->current;
    }

    /**
     * What the most recent changed() call found.
     *
     * @return list<string>
     */
    public function lastChanged(): array
    {
        return $this->lastChanged;
    }

    /**
     * Every component currently on disk, with its content hash.
     *
     * @return array<string, string> Component name => sha1 of the file.
     */
    public function currentHashes(): array
    {
        if (!is_dir($this->componentsDir)) {
            return [];
        }

        $hashes = [];
        $prefixLength = strlen(rtrim($this->componentsDir, '/\\')) + 1;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->componentsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'phue') {
                continue;
            }

            $path = $item->getPathname();
            $hash = sha1_file($path);

            if ($hash === false) {
                continue;
            }

            // Named by its path relative to the component root, so the same rule the loader
            // uses for a nested component ("Pages/Deep") applies to the report too.
            $hashes[substr($path, $prefixLength, -5)] = $hash;
        }

        return $hashes;
    }
}