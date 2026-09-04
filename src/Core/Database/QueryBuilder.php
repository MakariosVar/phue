<?php

namespace Phue\Core\Database;

use InvalidArgumentException;

/**
 * Fluent Query Builder for constructing safe PDO SQL statements.
 *
 * @package Phue\Core\Database
 */
class QueryBuilder
{
    protected string $fromTable = '';
    protected array $columns = ['*'];
    protected array $whereClauses = [];
    protected array $orderByClauses = [];
    protected array $bindings = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;

    /**
     * @param Connection $connection Active database connection manager.
     */
    public function __construct(
        protected Connection $connection
    ) {}

    /**
     * Sets the target table for the query.
     */
    public function table(string $table): static
    {
        $this->fromTable = $this->quoteIdentifier($table);
        return $this;
    }

    /**
     * Specifies the columns to select.
     */
    public function select(string ...$columns): static
    {
        $columns = !empty($columns) ? $columns : ['*'];
        $this->columns = array_map([$this, 'quoteIdentifier'], $columns);
        return $this;
    }

    /**
     * Adds a WHERE condition to the query (AND).
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhereClause('AND', $column, $operator, $value);
    }

    /**
     * Adds an OR WHERE condition to the query.
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhereClause('OR', $column, $operator, $value);
    }

    /**
     * Internal helper to handle WHERE / OR WHERE clause construction.
     */
    protected function addWhereClause(string $boolean, string $column, mixed $operator = null, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $quotedColumn = $this->quoteIdentifier($column);
        $cleanOperator = strtoupper(trim((string)$operator));

        // Append ESCAPE clause when doing LIKE queries to correctly handle escaped wildcards
        $sqlClause = "{$quotedColumn} {$operator} ?";
        if ($cleanOperator === 'LIKE' || $cleanOperator === 'NOT LIKE') {
            $sqlClause .= " ESCAPE '\\'";
        }

        $this->whereClauses[] = [
            'boolean' => $boolean,
            'sql'     => $sqlClause,
        ];
        
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Adds an ORDER BY clause to the query.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid ORDER BY direction [{$direction}]. Allowed: ASC, DESC.");
        }

        $quotedColumn = $this->quoteIdentifier($column);
        $this->orderByClauses[] = "{$quotedColumn} {$direction}";
        return $this;
    }

    /**
     * Sets the MAX number of records to fetch.
     */
    public function limit(int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Sets the OFFSET starting position for records.
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Compiles the SELECT query into raw SQL string with placeholders.
     */
    public function toSql(): string
    {
        if (empty($this->fromTable)) {
            throw new InvalidArgumentException('No table specified for QueryBuilder. Call table() first.');
        }

        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->fromTable;

        if (!empty($this->whereClauses)) {
            $whereParts = [];
            foreach ($this->whereClauses as $index => $clause) {
                $prefix = ($index === 0) ? '' : $clause['boolean'] . ' ';
                $whereParts[] = $prefix . $clause['sql'];
            }
            $sql .= ' WHERE ' . implode(' ', $whereParts);
        }

        if (!empty($this->orderByClauses)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderByClauses);
        }

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        return $sql;
    }

    /**
     * Returns parameter bindings for statement execution.
     *
     * @return array<int, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Executes the query and returns all matching records.
     *
     * @return array<int, mixed>
     */
    public function get(): array
    {
        $stmt = $this->connection->query($this->toSql(), $this->getBindings());
        return $stmt->fetchAll();
    }

    /**
     * Executes the query and returns the first matching record or null.
     *
     * @return array<string, mixed>|object|null
     */
    public function first(): array|object|null
    {
        $this->limit(1);
        $stmt = $this->connection->query($this->toSql(), $this->getBindings());
        $result = $stmt->fetch();

        return $result !== false ? $result : null;
    }

    /**
     * Retrieves the record count for the current query filters.
     */
    public function count(): int
    {
        $previousColumns = $this->columns;
        $previousLimit   = $this->limitValue;
        $previousOffset  = $this->offsetValue;

        $this->columns    = ['COUNT(*) as aggregate'];
        $this->limitValue  = null;
        $this->offsetValue = null;

        $stmt   = $this->connection->query($this->toSql(), $this->getBindings());
        $result = $stmt->fetch();

        // Restore query state
        $this->columns    = $previousColumns;
        $this->limitValue  = $previousLimit;
        $this->offsetValue = $previousOffset;

        if (is_array($result)) {
            return (int) ($result['aggregate'] ?? 0);
        }

        if (is_object($result)) {
            return (int) ($result->aggregate ?? 0);
        }

        return 0;
    }

    /**
     * Inserts a record into the database table.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): bool
    {
        if (empty($this->fromTable)) {
            throw new InvalidArgumentException('No table specified for QueryBuilder. Call table() first.');
        }

        $columns = array_map([$this, 'quoteIdentifier'], array_keys($values));
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->fromTable,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        return $this->connection->execute($sql, array_values($values)) > 0;
    }

    /**
     * Updates matching records in the database table.
     *
     * @param array<string, mixed> $values
     * @return int Affected rows count.
     */
    public function update(array $values): int
    {
        if (empty($this->fromTable)) {
            throw new InvalidArgumentException('No table specified for QueryBuilder. Call table() first.');
        }

        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $quotedColumn = $this->quoteIdentifier($column);
            $sets[] = "{$quotedColumn} = ?";
            $bindings[] = $value;
        }

        $sql = sprintf('UPDATE %s SET %s', $this->fromTable, implode(', ', $sets));

        if (!empty($this->whereClauses)) {
            $whereParts = [];
            foreach ($this->whereClauses as $index => $clause) {
                $prefix = ($index === 0) ? '' : $clause['boolean'] . ' ';
                $whereParts[] = $prefix . $clause['sql'];
            }
            $sql .= ' WHERE ' . implode(' ', $whereParts);
            $bindings = array_merge($bindings, $this->getBindings());
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Deletes matching records from the database table.
     *
     * @return int Affected rows count.
     */
    public function delete(): int
    {
        if (empty($this->fromTable)) {
            throw new InvalidArgumentException('No table specified for QueryBuilder. Call table() first.');
        }

        $sql = "DELETE FROM {$this->fromTable}";

        if (!empty($this->whereClauses)) {
            $whereParts = [];
            foreach ($this->whereClauses as $index => $clause) {
                $prefix = ($index === 0) ? '' : $clause['boolean'] . ' ';
                $whereParts[] = $prefix . $clause['sql'];
            }
            $sql .= ' WHERE ' . implode(' ', $whereParts);
        }

        return $this->connection->execute($sql, $this->getBindings());
    }

    /**
     * Safely quotes a database identifier (table or column name), bypassing wildcards and aggregates.
     */
    protected function quoteIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '*' || empty($value)) {
            return $value;
        }

        // 1. Process column aliases first (e.g., 'table.column AS alias')
        if (preg_match('/\s+as\s+/i', $value)) {
            [$column, $alias] = preg_split('/\s+as\s+/i', $value);
            return $this->quoteIdentifier($column) . ' AS ' . $this->quoteIdentifier($alias);
        }

        // 2. Process qualified identifiers second (e.g., 'table.column')
        if (str_contains($value, '.')) {
            $parts = explode('.', $value);
            return implode('.', array_map([$this, 'quoteIdentifier'], $parts));
        }

        // 3. Quote pure identifier name via driver-specific rules
        $driverName = $this->connection->getDriver()->getName();
        return SqlIdentifier::quote($value, $driverName);
    }
}