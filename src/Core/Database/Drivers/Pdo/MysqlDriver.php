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
     * PDO handle the cached server flavour was probed from.
     */
    private ?PDO $flavourPdo = null;

    /**
     * Cached MariaDB detection for the handle above.
     */
    private ?bool $mariaDb = null;

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

    /**
     * Returns the driver name.
     */
    public function getName(): string
    {
        return 'mysql';
    }

    /**
     * {@inheritdoc}
     */
    protected function doExplain(string $sql, array $params): array
    {
        $this->connect();

        $failures = [];

        foreach ($this->explainDialects() as $prefix) {
            try {
                $stmt = $this->pdo->prepare($prefix . ' ' . $sql);
                $stmt->execute($params);

                return $stmt->fetchAll();
            } catch (PDOException $e) {
                $failures[] = $prefix . ': ' . $e->getMessage();
            }
        }

        throw new RuntimeException(
            'EXPLAIN is not supported by this server (' . implode(' | ', $failures) . ')'
        );
    }

    /**
     * Statement prefixes to try, in probing order, for the connected flavour.
     *
     * MySQL 8.0.18+ answers to EXPLAIN ANALYZE; MariaDB rejects that spelling
     * and only knows ANALYZE. Plain EXPLAIN is the last resort for both.
     *
     * @return array<int, string> Statement prefixes
     */
    protected function explainDialects(): array
    {
        return $this->isMariaDb()
            ? ['ANALYZE', 'EXPLAIN']
            : ['EXPLAIN ANALYZE', 'EXPLAIN'];
    }

    /**
     * Whether the connected server reports itself as MariaDB.
     *
     * Cached per PDO handle: a persistent runtime keeps one connection for many
     * requests, and the flavour cannot change underneath it. The cache is keyed
     * by the handle so a reconnect re-probes instead of reusing a stale answer.
     *
     * @return bool True when the server identifies as MariaDB
     */
    protected function isMariaDb(): bool
    {
        if ($this->mariaDb !== null && $this->flavourPdo === $this->pdo) {
            return $this->mariaDb;
        }

        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        $this->flavourPdo = $this->pdo;
        $this->mariaDb    = stripos($version, 'mariadb') !== false;

        return $this->mariaDb;
    }
}