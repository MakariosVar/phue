<?php

namespace Phue\Core\Database\Drivers\Pdo;

use PDO;
use PDOException;
use RuntimeException;
use Phue\Core\Database\Drivers\Abstract\AbstractPdoDriver;

/**
 * PostgreSQL PDO Database Driver implementation.
 *
 * @package Phue\Core\Database\Drivers\Pdo
 */
class PostgresDriver extends AbstractPdoDriver
{
    /**
     * {@inheritdoc}
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $database = $this->config['database'] ?? '';
        $username = $this->config['username'] ?? 'postgres';
        $password = $this->config['password'] ?? '';
        $sslmode = $this->config['sslmode'] ?? 'prefer';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";

        $options = array_replace(
            $this->defaultOptions,
            $this->config['options'] ?? []
        );

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("PostgreSQL Connection Error: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Returns the driver name.
     */
    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     */
    protected function doExplain(string $sql, array $params): array
    {
        $this->connect();

        $explainSql = 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $sql;
        $stmt = $this->pdo->prepare($explainSql);
        $stmt->execute($params);

        $result = $stmt->fetchAll();

        // Postgres returns JSON array with one element containing the plan
        if ($result !== [] && isset($result[0][0])) {
            $plan = $result[0][0];
            if (is_array($plan)) {
                return $plan;
            }
        }

        return $result;
    }
}