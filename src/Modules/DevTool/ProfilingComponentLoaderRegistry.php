<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponent;

/**
 * Class ProfilingComponentLoaderRegistry
 *
 * Records every component-resolution call as a named span, so the debug bar can show
 * how many times the loader was actually entered per request.
 *
 * Extends rather than wraps ComponentLoaderRegistry on purpose. PhueComponentLoader has
 * no interface and its constructor takes framework wiring, so a wrapping decorator would
 * have to re-create that wiring (and would break silently whenever it changes). Subclassing
 * inherits every untouched method, keeps the container wiring in one place, and still lets
 * us time only the calls that matter.
 *
 * moduleHash() is the entry point that matters: PhueComponentLoader::buildFor() resolves
 * every child's hash through the registry, which re-enters buildFor() for that child. The
 * call count of this one method is therefore the direct measure of dependency-graph
 * re-traversal, which is the failure mode a component-heavy page falls into.
 *
 * @package Phue\Modules\DevTool
 */
class ProfilingComponentLoaderRegistry extends ComponentLoaderRegistry
{
    /**
     * @param \Psr\Log\LoggerInterface|null $logger Logger forwarded to the parent registry.
     * @param RequestProfiler $profiler Collector receiving the spans.
     */
    public function __construct(
        ?\Psr\Log\LoggerInterface $logger,
        private RequestProfiler $profiler
    ) {
        parent::__construct($logger);
    }

    /**
     * Resolve a component's client module hash, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null 8-character hash prefix or null.
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
     * Evaluate a component, recording the call.
     *
     * @param string $name Component logical name.
     * @param array<string, mixed> $params Evaluation parameters.
     * @return PhueComponent|null
     */
    public function load(string $name, array $params = []): ?PhueComponent
    {
        return $this->profiler->measure(
            'loader.load',
            fn(): ?PhueComponent => parent::load($name, $params),
            ['component' => $name]
        );
    }

    /**
     * Resolve a raw component's hash without dependency scanning, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null 8-character hash prefix or null.
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
     * Build the SSR-only module for a component, recording the call.
     *
     * @param string $name Component logical name.
     * @return string|null Compiled SSR module content or null.
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
     * Resolve a compiled client module by hash, recording the call.
     *
     * @param string $name Component logical name.
     * @param string $hash 8-character hash prefix.
     * @return string|null Module content or null.
     */
    public function moduleFor(string $name, string $hash): ?string
    {
        return $this->profiler->measure(
            'loader.moduleFor',
            fn(): ?string => parent::moduleFor($name, $hash),
            ['component' => $name]
        );
    }
}
