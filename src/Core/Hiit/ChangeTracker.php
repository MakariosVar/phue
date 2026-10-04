<?php

declare(strict_types=1);

namespace Phue\Core\Hiit;

use Phue\Core\UI\PhueComponentLoader;

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
     */
    public function __construct(iterable $loaders)
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
        $this->known = $known;
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