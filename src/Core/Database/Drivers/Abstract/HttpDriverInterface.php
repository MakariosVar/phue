<?php

namespace Phue\Core\Database\Drivers\Abstract;

/**
 * Interface contract specifically for HTTP/Serverless Edge Drivers (Neon, Turso, PlanetScale).
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
interface HttpDriverInterface extends DriverInterface
{
    /**
     * Returns the target HTTP endpoint URL used by the edge driver.
     */
    public function getEndpoint(): string;
}