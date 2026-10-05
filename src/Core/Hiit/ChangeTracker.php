<?php

declare(strict_types=1);

namespace Phue\Core\Hiit;

use Phue\Core\UI\PhueComponentLoader;
use Psr\SimpleCache\CacheInterface;

/**
 * Reports which components moved since it last looked.
 *
 * The state lives here rather than in the caller because "changed since when" needs an answer
 * about the previous look, and the previous look is the one thing a stateless request handler
 * cannot supply. A handler that carried its own baseline was rebuilt on every request by
 * Container::make() — which calls the factory every time and skips the singleton cache — so it
 * compared the filesystem against itself and answered "nothing changed", correctly formatted,
 * forever.
 *
 * Deliberately free of any transport concern. It knows about directories and content hashes and
 * nothing about HTTP, so a WebSocket channel, a console watcher and a poll endpoint can all
 * share one implementation.
 *
 * The baseline can be persisted, and on a short-lived runtime it has to be. Under Swoole the
 * process is long-lived, so keeping the baseline in memory works and costs nothing; under FPM or
 * PHP's built-in server every request is a separate execution, so a tracker built per request
 * compares the filesystem against itself and reports nothing, forever. Nothing about that failure
 * is visible from the outside: the endpoint answers 200 with an empty list.
 *
 * The loaders are read once, when the tracker is built. Modules register their loaders during
 * boot and the registry is fixed by the time any request arrives, so re-reading it per call
 * would cost a directory scan to reach the same answer.
 *
 * @package Phue\Core\Hiit
 */
final class ChangeTracker
{
    /**
     * One detector per component directory.
     *
     * @var list<Hiit>
     */
    private readonly array $hiits;

    /**
     * The component directories being watched.
     *
     * @var list<string>
     */
    private readonly array $directories;

    /**
     * What each detector reported last time.
     *
     * @var list<array<string, string>>
     */
    private array $known = [];

    /**
     * @param iterable<PhueComponentLoader> $loaders Whatever already knows where components live.
     *        Asking the loaders is deliberate: a second list of directories anywhere else is a
     *        list that starts drifting the first time a module registers a loader of its own.
     * @param CacheInterface|null $cache Where the baseline survives a process, or null to keep it
     *        in memory. Required in practice on any runtime that ends the process between checks.
     *        Typed as the PSR interface rather than the framework's own, because that is what an
     *        application binds: `Application` registers `Psr\SimpleCache\CacheInterface`, so a
     *        parameter asking for `Phue\Core\Cache\CacheInterface` silently received null and the
     *        persistence looked configured while doing nothing.
     */
    public function __construct(iterable $loaders, private readonly ?CacheInterface $cache = null)
    {
        $seen = [];

        foreach ($loaders as $loader) {
            $real = realpath($loader->componentsDir());

            if ($real !== false) {
                $seen[$real] = true;
            }
        }

        $directories = array_keys($seen);
        sort($directories);

        $this->directories = $directories;

        // Built locally and assigned once: a readonly array cannot be appended to, only
        // initialised, and both lists have to stay index-aligned.
        $hiits = [];
        $known = [];

        foreach ($directories as $directory) {
            $hiit = new Hiit($directory);
            $hiits[] = $hiit;

            // Primed here rather than by discarding the first answer. A comparison against an
            // empty baseline calls every component on disk new, so the first call would report
            // the entire project and whatever is polling would act on all of it.
            $known[] = $hiit->currentHashes();
        }

        $this->hiits = $hiits;

        // A stored baseline is preferred over a fresh prime, because a fresh prime is exactly the
        // state that cannot see a change on a short-lived runtime. Keyed by the directories as
        // well as by the name, so pointing a project at a different component tree primes again
        // instead of comparing two unrelated trees and reporting every file in both.
        $restored = $cache === null ? null : $cache->get($this->cacheKey());

        $this->known = is_array($restored) && count($restored) === count($known)
            ? array_map(static fn(array $row): array => (array) $row, $restored)
            : $known;

        if ($cache !== null && $restored === null) {
            $this->persist($this->known);
        }
    }

    /**
     * @param list<array<string, string>> $known
     */
    private function persist(array $known): void
    {
        $this->cache?->set($this->cacheKey(), $known);
    }

    private function cacheKey(): string
    {
        return 'phue.hiit.baseline.' . substr(hash('sha256', implode('|', $this->directories)), 0, 16);
    }

    /**
     * Component names that changed since the previous call, across every directory.
     *
     * The baseline moves as it answers, so the same change is reported once. A caller that
     * wanted to ask the same question twice about an unchanged tree gets nothing, which is the
     * only way "changed" can mean anything.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        $names = [];

        foreach ($this->hiits as $index => $hiit) {
            foreach ($hiit->changed($this->known[$index]) as $name) {
                $names[] = $name;
            }

            // The snapshot the comparison just made, so a poll costs one scan rather than two.
            $this->known[$index] = $hiit->snapshot();
        }

        $names = array_values(array_unique($names));
        sort($names);

        // Written on every check, not only when something moved: the baseline has to be current
        // even on a run where nothing changed, or the next process would compare against a stale
        // snapshot and report a change that already happened.
        $this->persist($this->known);

        return $names;
    }

    /**
     * The directories being watched.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }
}