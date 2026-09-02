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
}