<?php

namespace Phue\Core\Database\Drivers\Pdo;

use PDO;
use PDOStatement as NativePDOStatement;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;

/**
 * PDO-specific implementation of the StatementInterface contract.
 *
 * @package Phue\Core\Database\Drivers\Pdo
 */
class PdoStatement implements StatementInterface
{
    /**
     * @param NativePDOStatement $statement The wrapped native PDOStatement object.
     */
    public function __construct(
        protected NativePDOStatement $statement
    ) {}

    /**
     * {@inheritdoc}
     */
    public function fetch(): array|false
    {
        return $this->statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(int $columnIndex = 0): mixed
    {
        return $this->statement->fetchColumn($columnIndex);
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }
}