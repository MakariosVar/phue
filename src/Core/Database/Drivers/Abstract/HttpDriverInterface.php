<?php

declare(strict_types=1);

namespace Phue\Core\Database\Drivers\Abstract;

/**
 * Interface contract for Serverless/Edge HTTP Database Drivers.
 *
 * Allows Phue ActiveRecord and QueryBuilder to execute SQL over REST APIs
 * without relying on traditional PDO TCP socket connections.
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
interface HttpDriverInterface
{
    /**
     * Returns the target database engine dialect name ('sqlite', 'pgsql', etc.).
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Sets the REST API endpoint URL for the database service.
     *
     * @param string $endpoint Fully qualified HTTP/HTTPS endpoint.
     * @return self
     */
    public function setEndpoint(string $endpoint): self;

    /**
     * Sets the Bearer or API authentication token.
     *
     * @param string $token Authentication token.
     * @return self
     */
    public function setAuthToken(string $token): self;

    /**
     * Executes a raw SQL query with optional parameters over HTTP.
     *
     * @param string $sql The SQL statement to execute.
     * @param array<string|int, mixed> $params Prepared statement bound parameters.
     * @return array<int, array<string, mixed>> Result set formatted as an associative array.
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Executes a write statement (INSERT, UPDATE, DELETE) and returns affected rows.
     *
     * @param string $sql The SQL statement to execute.
     * @param array<string|int, mixed> $params Prepared statement bound parameters.
     * @return int Number of affected rows or last inserted auto-increment ID.
     */
    public function execute(string $sql, array $params = []): int;
}