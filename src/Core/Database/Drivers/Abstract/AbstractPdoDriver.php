<?php

namespace Phue\Core\Database\Drivers\Abstract;

use PDO;
use PDOException;
use RuntimeException;
use Phue\Core\Database\Drivers\Pdo\PdoStatement;

/**
 * Base abstract class providing common PDO operations for traditional RDBMS drivers.
 *
 * @package Phue\Core\Database\Drivers\Abstract
 */
abstract class AbstractPdoDriver implements PdoDriverInterface
{
    /**
     * Active PDO instance or null if disconnected.
     */
    protected ?PDO $pdo = null;

    /**
     * Default PDO configuration options.
     *
     * @var array<int, mixed>
     */
    protected array $defaultOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    /**
     * @param array<string, mixed> $config Connection options (host, database, username, etc.)
     */
    public function __construct(
        protected array $config
    ) {}

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPdo(): PDO
    {
        $this->connect();
        
        if ($this->pdo === null) {
            throw new RuntimeException('Failed to establish a valid PDO connection.');
        }

        return $this->pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql, array $params = []): StatementInterface
    {
        $this->connect();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return new PdoStatement($stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->connect();

        if (empty($params)) {
            return $this->pdo->exec($sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(?string $name = null): string|false
    {
        $this->connect();
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Transaction Control Methods
     */
    public function beginTransaction(): bool
    {
        $this->connect();
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        $this->connect();
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        $this->connect();
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        return $this->pdo->inTransaction();
    }
}