<?php

namespace Phue\Core\Database\Drivers\Abstract;

use PDO;

/**
 * Interface contract specifically for native PDO-backed drivers.
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
interface PdoDriverInterface extends DriverInterface
{
    /**
     * Returns the underlying native PDO connection instance.
     *
     * @return PDO
     */
    public function getPdo(): PDO;

    /**
     * Returns the driver name identifier (e.g. 'sqlite', 'mysql', 'pgsql').
     *
     * @return string
     */
    public function getName(): string;
}