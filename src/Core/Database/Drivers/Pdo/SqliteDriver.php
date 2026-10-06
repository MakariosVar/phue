<?php

namespace Phue\Core\Database\Drivers\Pdo;

use PDO;
use PDOException;
use RuntimeException;
use Phue\Core\Database\Drivers\Abstract\AbstractPdoDriver;

/**
 * SQLite implementation for PDO Database Driver.
 *
 * @package Phue\Core\Database\Drivers\Pdo
 */
class SqliteDriver extends AbstractPdoDriver
{
    /**
     * {@inheritdoc}
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $database = $this->config['database'] ?? ':memory:';
        $dsn = "sqlite:{$database}";

        $options = array_replace(
            $this->defaultOptions,
            $this->config['options'] ?? []
        );

        try {
            $this->pdo = new PDO($dsn, null, null, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("SQLite Connection Error: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Returns the driver name.
     */
    public function getName(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    protected function doExplain(string $sql, array $params): array
    {
        $this->connect();

        $explainSql = 'EXPLAIN QUERY PLAN ' . $sql;
        $stmt = $this->pdo->prepare($explainSql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}