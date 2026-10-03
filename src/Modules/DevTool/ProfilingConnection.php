<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Database\Connection;

/**
 * Class ProfilingConnection
 *
 * A Connection whose driver reports its work to the DevTool profiler.
 *
 * Subclasses rather than decorates because Connection holds its driver in a protected
 * property and exposes no seam to replace it: a wrapper would have to forward eleven
 * methods by hand and would stop type-checking against Connection the moment the driver
 * API grew. Reaching into the protected property from a subclass is the same relationship
 * a subclass is for, and it means every inherited method — including the __call proxy and
 * table() query builder — is instrumented without being restated.
 *
 * The connection itself is untouched: same configuration, same driver, same semantics.
 * Only the reporting is new.
 *
 * @package Phue\Modules\DevTool
 */
class ProfilingConnection extends Connection
{
    /**
     * @param array<string, mixed> $config Database configuration options.
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @param bool $redactLiterals Whether quoted literals are removed from recorded SQL.
     */
    public function __construct(
        array $config,
        private RequestProfiler $profiler,
        private bool $redactLiterals = true
    ) {
        parent::__construct($config);

        $this->driver = new ProfilingDriver($this->driver, $profiler, $redactLiterals);
    }

    /**
     * Whether recorded SQL has its literals redacted.
     *
     * @return bool
     */
    public function isSqlRedacted(): bool
    {
        return $this->redactLiterals;
    }

    /**
     * Returns the collector this connection reports to.
     *
     * @return RequestProfiler
     */
    public function getProfiler(): RequestProfiler
    {
        return $this->profiler;
    }
}