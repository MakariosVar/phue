<?php

namespace Phue\Core\Database\Drivers\Abstract;

use PDO;

/**
 * Universal contract for all database drivers (PDO and HTTP Edge drivers).
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
interface DriverInterface
{
    /**
     * Establishes the connection to the database.
     */
    public function connect(): void;

    /**
     * Closes the active database connection.
     */
    public function disconnect(): void;

    /**
     * Checks whether an active connection is established.
     */
    public function isConnected(): bool;

    /**
     * Executes a SQL statement with parameter bindings and returns a StatementInterface.
     *
     * @param string $sql Prepared statement query string.
     * @param array<string|int, mixed> $params Parameter bindings.
     * @return StatementInterface
     */
    public function query(string $sql, array $params = []): StatementInterface;

    /**
     * Executes an INSERT, UPDATE, or DELETE SQL statement and returns affected rows.
     *
     * @param string $sql Raw SQL or prepared statement.
     * @param array<string|int, mixed> $params
     * @return int Number of affected rows.
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Returns the ID of the last inserted row or sequence value.
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Initiates a database transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commits the current transaction.
     */
    public function commit(): bool;

    /**
     * Rolls back the current transaction.
     */
    public function rollBack(): bool;

    /**
     * Checks if a transaction is currently active.
     */
    public function inTransaction(): bool;

    /**
     * Returns the lowercased identifier of the driver (e.g. 'sqlite', 'mysql', 'pgsql').
     */
    public function getName(): string;
}