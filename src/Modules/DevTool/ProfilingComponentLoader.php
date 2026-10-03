<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueComponent;
use Phue\Core\UI\TemplateCompilerBridge;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Class ProfilingComponentLoader
 *
 * Records every component build and resolution on the loader itself, so the report shows
 * the work a component-heavy page actually does.
 *
 * Subclass rather than wrap: PhueComponentLoader has no interface and takes framework
 * wiring in its constructor, so a wrapping decorator would have to re-derive that wiring
 * and would break silently whenever the container's factory changes. Inheriting also
 * means only the measured methods are overridden — everything else, including the
 * memoization the loader depends on, behaves exactly as before.
 *
 * Why the loader and not only the registry: ComponentRenderer resolves a component's
 * module hash straight off the loader (see ComponentRenderer::renderComponentAsModule),
 * bypassing ComponentLoaderRegistry entirely. Instrumenting the registry alone therefore
 * misses the dominant cost on a page with many components — a profiler that hides the hot
 * path is worse than no profiler.
 *
 * @package Phue\Modules\DevTool
 */
class ProfilingComponentLoader extends PhueComponentLoader
{
    /**
     * @param string $componentsDir Base directory containing .phue component files.
     * @param CacheInterface|null $persistentCache PSR-16 cache for compiled artefacts.
     * @param string|null $viewsCacheDir Directory for compiled template artefacts.
     * @param TemplateCompilerBridge $compilerBridge Bridge used to compile templates.
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @param LoggerInterface|null $logger Logger forwarded to the parent loader.
     * @param PhueComponentLoader|null $source Loader whose identity (memo tables) to adopt.
     */
    public function __construct(
        string $componentsDir,
        ?CacheInterface $persistentCache,
        ?string $viewsCacheDir,
        TemplateCompilerBridge $compilerBridge,
        private RequestProfiler $profiler,
        ?LoggerInterface $logger = null,
        private ?PhueComponentLoader $source = null
    ) {
        parent::__construct(
            componentsDir: $componentsDir,
            parser: null,
            persistentCache: $persistentCache,
            viewsCacheDir: $viewsCacheDir,
            compilerBridge: $compilerBridge,
            logger: $logger
        );

        if ($source !== null) {
            $this->adoptMemoFrom($source);
        }
    }

    /**
     * Evaluate a component, recording the call.
     *
     * @param string $name Component logical name.
     * @param array<string, mixed> $params Evaluation parameters.
     * @return PhueComponent
     */
    public function load(string $name, array $params = []): PhueComponent
    {
        return $this->profiler->measure(
            'loader.component.load',
            fn(): PhueComponent => parent::load($name, $params),
            ['component' => $name]
        );
    }

    /**
     * Build the client module for a component, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null
     */
    public function buildFor(string $name): ?string
    {
        return $this->profiler->measure(
            'loader.buildFor',
            fn(): ?string => parent::buildFor($name),
            ['component' => $name]
        );
    }

    /**
     * Build the SSR-only module for a component, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null
     */
    public function buildForSsr(string $name): ?string
    {
        return $this->profiler->measure(
            'loader.buildForSsr',
            fn(): ?string => parent::buildForSsr($name),
            ['component' => $name]
        );
    }

    /**
     * Resolve the raw structure of a component, recording the call.
     *
     * @param string $name Component logical name.
     * @return array<string, mixed>|null
     */
    public function getStructure(string $name): ?array
    {
        return $this->profiler->measure(
            'loader.getStructure',
            fn(): ?array => parent::getStructure($name),
            ['component' => $name]
        );
    }

    /**
     * Resolve a component's client module hash, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null
     */
    public function moduleHash(string $name): ?string
    {
        return $this->profiler->measure(
            'loader.moduleHash',
            fn(): ?string => parent::moduleHash($name),
            ['component' => $name]
        );
    }

    /**
     * Resolve a raw component's hash without dependency scanning, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null
     */
    public function rawModuleHash(string $name): ?string
    {
        return $this->profiler->measure(
            'loader.rawModuleHash',
            fn(): ?string => parent::rawModuleHash($name),
            ['component' => $name]
        );
    }

    /**
     * Resolve a compiled client module by hash, recording the call.
     *
     * @param string $name Component logical name.
     * @param string $hash 8-character hash prefix.
     * @return string|null
     */
    public function moduleFor(string $name, string $hash): ?string
    {
        return $this->profiler->measure(
            'loader.moduleFor',
            fn(): ?string => parent::moduleFor($name, $hash),
            ['component' => $name]
        );
    }

    /**
     * Resolve a component's SSR module hash, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null
     */
    public function ssrModuleHash(string $name): ?string
    {
        return $this->profiler->measure(
            'loader.ssrModuleHash',
            fn(): ?string => parent::ssrModuleHash($name),
            ['component' => $name]
        );
    }

    /**
     * Copy the memoization tables of the loader being replaced.
     *
     * A component-heavy page builds thousands of modules per request and relies on these
     * L1 tables to avoid rebuilding them. Replacing the loader with a fresh instance that
     * starts with empty tables would not just lose the measurements — it would make the
     * page dramatically slower, and the slowdown would be invisible in a report that only
     * counts what the profiler can see.
     *
     * @param PhueComponentLoader $source Loader whose state to adopt.
     * @return void
     */
    private function adoptMemoFrom(PhueComponentLoader $source): void
    {
        foreach (['ssrMemo', 'clientMemo', 'templateChildrenMemo'] as $property) {
            // No setAccessible(): the property is declared on PhueComponentLoader, so a
            // subclass may read and write it directly. Calling it is deprecated in 8.5.
            $reflection = new \ReflectionProperty(PhueComponentLoader::class, $property);
            $reflection->setValue($this, $reflection->getValue($source));
        }
    }
}
