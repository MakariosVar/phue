<?php

declare(strict_types=1);

namespace Phue\Core\Frontend;

use Phue\Core\UI\PhueComponentLoader;
use Throwable;

/**
 * Class ComponentLoaderRegistry
 *
 * Composite loader stack with fail-safe execution. Skips missing paths and individual loader errors.
 *
 * @package Phue\Core\Frontend
 */
class ComponentLoaderRegistry
{
    /**
     * @var array<int, PhueComponentLoader|callable(): ?PhueComponentLoader>
     */
    private array $loaders = [];

    /**
     * Register a loader instance or a lazy factory callable that returns a loader (or null on failure).
     *
     * @param PhueComponentLoader|callable $loader Loader instance or lazy resolver factory.
     *
     * @return void
     */
    public function addLoader(PhueComponentLoader|callable $loader): void
    {
        $this->loaders[] = $loader;
    }

    /**
     * Iterates through candidate loaders (App first, then modules) and returns the first non-null script payload.
     *
     * @param string $name Component name.
     * @param string $hash SHA-1 hash prefix.
     *
     * @return string|null JavaScript content or null if not found/unresolvable across all candidates.
     */
    public function scriptFor(string $name, string $hash): ?string
    {
        foreach ($this->loaders as $candidate) {
            try {
                /** @var PhueComponentLoader|null $loader */
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader === null) {
                    continue;
                }

                $script = $loader->scriptFor($name, $hash);

                if ($script !== null) {
                    return $script;
                }
            } catch (Throwable) {
                // Gracefully skip invalid paths, missing directories, or candidate resolution errors
                continue;
            }
        }

        return null;
    }
}