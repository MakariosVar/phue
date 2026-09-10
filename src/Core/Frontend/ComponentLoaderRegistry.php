<?php

declare(strict_types=1);

namespace Phue\Core\Frontend;

use Phue\Core\UI\PhueComponent;
use Phue\Core\UI\PhueComponentLoader;
use Throwable;

/**
 * Class ComponentLoaderRegistry
 *
 * Composite loader stack with fail-safe execution.
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
     * Register a loader instance or a lazy factory callable that returns a loader.
     *
     * @param PhueComponentLoader|callable $loader
     *
     * @return void
     */
    public function addLoader(PhueComponentLoader|callable $loader): void
    {
        $this->loaders[] = $loader;
    }

    /**
     * Iterates candidate loaders in priority order and returns the evaluated PhueComponent.
     *
     * @param string               $name   Component logical name.
     * @param array<string, mixed> $params Evaluation parameters.
     *
     * @return PhueComponent|null
     */
    public function load(string $name, array $params = []): ?PhueComponent
    {
        foreach ($this->loaders as $candidate) {
            try {
                /** @var PhueComponentLoader|null $loader */
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader === null) {
                    continue;
                }

                return $loader->load($name, $params);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Resolves raw script content across candidate loaders and returns the hashed asset URL.
     *
     * @param string $name Component logical name.
     *
     * @return string|null Asset URL (e.g., "/assets/phue/PhButton.a1b2c3d4.phue.js") or null.
     */
    public function scriptUrl(string $name): ?string
    {
        foreach ($this->loaders as $candidate) {
            try {
                /** @var PhueComponentLoader|null $loader */
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader === null) {
                    continue;
                }

                $script = $loader->contentFor($name);
                if ($script !== null) {
                    $hash = substr(sha1($script), 0, 8);
                    return "/assets/phue/{$name}.{$hash}.phue.js";
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Iterates candidate loaders and returns the first script payload matching the given hash.
     *
     * @param string $name Component logical name.
     * @param string $hash SHA-1 8-character hash prefix.
     *
     * @return string|null JavaScript content or null if not found across candidates.
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
                continue;
            }
        }

        return null;
    }

    /**
     * Resolves SFC module content across candidate loaders and returns the hashed asset URL.
     *
     * @param string $name Component logical name.
     *
     * @return string|null Asset URL (e.g., "/assets/phue/PhButton.a1b2c3d4.phue.js") or null.
     */
    public function moduleUrl(string $name): ?string
    {
        foreach ($this->loaders as $candidate) {
            try {
                /** @var PhueComponentLoader|null $loader */
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader === null) {
                    continue;
                }

                $hash = $loader->moduleHash($name);
                if ($hash !== null) {
                    return "/assets/phue/{$name}.{$hash}.phue.js";
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Iterates candidate loaders and returns the first JS module payload matching the given hash.
     *
     * @param string $name Component logical name.
     * @param string $hash SHA-1 8-character hash prefix.
     *
     * @return string|null JS module content or null if not found across candidates.
     */
    public function moduleFor(string $name, string $hash): ?string
    {
        foreach ($this->loaders as $candidate) {
            try {
                /** @var PhueComponentLoader|null $loader */
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader === null) {
                    continue;
                }

                $module = $loader->moduleFor($name, $hash);

                if ($module !== null) {
                    return $module;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}