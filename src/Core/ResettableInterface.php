<?php

namespace Phue\Core;

/**
 * Contract for services that store request state and need resetting in persistent runtimes.
 */
interface ResettableInterface
{
    /**
     * Reset the service state back to its initial clean condition.
     */
    public function reset(): void;
}