<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use Phue\Core\UI\PhueComponentLoader;

/**
 * Instrumented PhueComponentLoader that counts public entry-point calls.
 *
 * Wall-clock assertions are machine-dependent and therefore useless as
 * regression guards. The component resolution cost, however, is a function of
 * the *shape* of the algorithm: a loader that memoises its name -> path index
 * and its per-node module hashes performs O(V+E) work for a dependency graph of
 * V nodes, while the pre-optimisation implementation re-walked the whole graph
 * once per queried node (O(V*E)) and re-scanned the component directory twice
 * per unresolved name.
 *
 * Counting calls instead of milliseconds pins that shape deterministically on
 * any machine: the old implementation issued ~42 buildFor() calls per component,
 * the current one issues under 2.
 */
final class CountingComponentLoader extends PhueComponentLoader
{
    public int $buildForCalls = 0;
    public int $buildForSsrCalls = 0;
    public int $hasComponentCalls = 0;
    public int $getStructureCalls = 0;
    public int $moduleHashCalls = 0;

    public function buildFor(string $name): ?string
    {
        $this->buildForCalls++;

        return parent::buildFor($name);
    }

    public function buildForSsr(string $name): ?string
    {
        $this->buildForSsrCalls++;

        return parent::buildForSsr($name);
    }

    public function hasComponent(string $name): bool
    {
        $this->hasComponentCalls++;

        return parent::hasComponent($name);
    }

    public function getStructure(string $name): ?array
    {
        $this->getStructureCalls++;

        return parent::getStructure($name);
    }

    public function moduleHash(string $name): ?string
    {
        $this->moduleHashCalls++;

        return parent::moduleHash($name);
    }

    public function resetCounters(): void
    {
        $this->buildForCalls = 0;
        $this->buildForSsrCalls = 0;
        $this->hasComponentCalls = 0;
        $this->getStructureCalls = 0;
        $this->moduleHashCalls = 0;
    }
}
