<?php

namespace Phue\Core\Database\Drivers\Pdo;

use PDO;
use PDOException;
use RuntimeException;
use Phue\Core\Database\Drivers\Abstract\AbstractPdoDriver;

/**
 * MySQL PDO Database Driver implementation.
 *
 * @package Phue\Core\Database\Drivers\Pdo
 */
class MysqlDriver extends AbstractPdoDriver
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
        $port = $this->config['port'] ?? 3306;
        $database = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';
        $username = $this->config['username'] ?? 'root';
        $password = $this->config['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $options = array_replace(
            $this->defaultOptions,
            $this->config['options'] ?? []
        );

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("MySQL Connection Error: " . $e->getMessage(), (int) $e->getCode());
        }
    }
}