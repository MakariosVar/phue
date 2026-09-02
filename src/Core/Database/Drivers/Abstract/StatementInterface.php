<?php

namespace Phue\Core\Database\Drivers\Abstract;

/**
 * Abstraction layer for query results, unifying PDOStatement and HTTP Edge API responses.
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
interface StatementInterface
{
    /**
     * Fetches a single row from the result set as an associative array.
     *
     * @return array<string, mixed>|false Returns false if no more rows exist.
     */
    public function fetch(): array|false;

    /**
     * Fetches all remaining rows from the result set as an array of associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Fetches a single column value from the next row of a result set.
     */
    public function fetchColumn(int $columnIndex = 0): mixed;

    /**
     * Returns the number of rows affected by the last SQL statement.
     */
    public function rowCount(): int;
}