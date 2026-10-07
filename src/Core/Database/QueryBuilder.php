<?php

declare(strict_types=1);

namespace Phue\Core\Database;

use InvalidArgumentException;
use Phue\Core\Container;
use Phue\Core\Debug\AsyncDumper;

/**
 * Fluent Query Builder for constructing safe PDO SQL statements.
 *
 * @package Phue\Core\Database
 */
class QueryBuilder
{
    /**
     * Target database table name quoted for SQL execution.
     */
    protected string $fromTable = '';

    /**
     * Selected column names or expressions.
     *
     * @var array<int, string>
     */
    protected array $columns = ['*'];

    /**
     * Accumulated WHERE condition definitions.
     *
     * @var array<int, array{boolean: string, sql: string}>
     */
    protected array $whereClauses = [];

    /**
     * Accumulated ORDER BY clauses.
     *
     * @var array<int, string>
     */
    protected array $orderByClauses = [];

    /**
     * Prepared statement parameter bindings.
     *
     * @var array<int, mixed>
     */
    protected array $bindings = [];

    /**
     * Maximum limit of records to return.
     */
    protected ?int $limitValue = null;

    /**
     * Offset starting position for returned records.
     */
    protected ?int $offsetValue = null;

    /**
     * Initialize QueryBuilder with an active connection manager.
     *
     * @param Connection $connection Active database connection manager.
     */
    public function __construct(
        protected Connection $connection
    ) {}

    /**
     * Sets the target table for the query.
     *
     * @param string $table Name of table to target.
     * @return static
     */
    public function table(string $table): static
    {
        $this->fromTable = $this->quoteIdentifier($table);
        return $this;
    }

    /**
     * Specifies the columns to select.
     *
     * @param string ...$columns List of column names to retrieve.
     * @return static
     */
    public function select(string ...$columns): static
    {
        $columns = !empty($columns) ? $columns : ['*'];
        $this->columns = array_map([$this, 'quoteIdentifier'], $columns);
        return $this;
    }

    /**
     * Adds a WHERE condition to the query (AND).
     *
     * @param string $column Column name to compare.
     * @param mixed $operator Logical operator or target value if 2 arguments supplied.
     * @param mixed $value Target comparison value.
     * @return static
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhereClause('AND', $column, $operator, $value);
    }

    /**
     * Adds an OR WHERE condition to the query.
     *
     * @param string $column Column name to compare.
     * @param mixed $operator Logical operator or target value if 2 arguments supplied.
     * @param mixed $value Target comparison value.
     * @return static
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhereClause('OR', $column, $operator, $value);
    }

    /**
     * Adds a WHERE IN clause to the query (AND).
     *
     * @param string $column Column name to match against array of values.
     * @param array<int|string, mixed> $values List of values to match.
     * @return static
     */
    public function whereIn(string $column, array $values): static
    {
        return $this->addWhereInClause('AND', $column, $values);
    }

    /**
     * Adds an OR WHERE IN clause to the query.
     *
     * @param string $column Column name to match against array of values.
     * @param array<int|string, mixed> $values List of values to match.
     * @return static
     */
    public function orWhereIn(string $column, array $values): static
    {
        return $this->addWhereInClause('OR', $column, $values);
    }

    /**
     * Internal helper to handle WHERE IN clause construction.
     *
     * @param string $boolean Logical join boolean ('AND' or 'OR').
     * @param string $column Column name to match.
     * @param array<int|string, mixed> $values Array of target values.
     * @return static
     */
    protected function addWhereInClause(string $boolean, string $column, array $values): static
    {
        $quotedColumn = $this->quoteIdentifier($column);

        // An empty IN array matches no records safely
        if (empty($values)) {
            $this->whereClauses[] = [
                'boolean' => $boolean,
                'sql'     => '1 = 0',
            ];
            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sqlClause = "{$quotedColumn} IN ({$placeholders})";

        $this->whereClauses[] = [
            'boolean' => $boolean,
            'sql'     => $sqlClause,
        ];

        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * Internal helper to handle WHERE / OR WHERE clause construction.
     *
     * @param string $boolean Logical join boolean ('AND' or 'OR').
     * @param string $column Column name to compare.
     * @param mixed $operator Comparison operator or value.
     * @param mixed $value Target comparison value.
     * @return static
     * @throws InvalidArgumentException If an invalid operator is provided.
     */
    protected function addWhereClause(string $boolean, string $column, mixed $operator = null, mixed $value = null): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $quotedColumn = $this->quoteIdentifier($column);
        $cleanOperator = strtoupper(trim((string)$operator));

        // Allowed SQL comparison operators whitelist
        $allowedOperators = [
            '=', '!=', '<>', '<', '>', '<=', '>=', 
            'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
            'IS', 'IS NOT'
        ];

        if (!in_array($cleanOperator, $allowedOperators, true)) {
            throw new InvalidArgumentException("Invalid operator provided for WHERE clause: [{$operator}]");
        }

        // Append ESCAPE clause when doing LIKE queries to correctly handle escaped wildcards
        $sqlClause = "{$quotedColumn} {$cleanOperator} ?";
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
     *
     * @param string $column Column name to order by.
     * @param string $direction Ordering direction ('ASC' or 'DESC').
     * @return static
     * @throws InvalidArgumentException If an invalid direction is provided.
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
     *
     * @param int $limit Maximum number of records.
     * @return static
     */
    public function limit(int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Sets the OFFSET starting position for records.
     *
     * @param int $offset Starting offset position.
     * @return static
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Compiles the SELECT query into raw SQL string with placeholders.
     *
     * @return string Compiled SQL string.
     * @throws InvalidArgumentException If no table was defined.
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
     * @return array<int, mixed> Positioned bindings list.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Executes the query and returns all matching records.
     *
     * @return array<int, mixed> Set of fetched record rows.
     */
    public function get(): array
    {
        $stmt = $this->connection->query($this->toSql(), $this->getBindings());
        return $stmt->fetchAll();
    }

    /**
     * Executes the query and returns the first matching record or null.
     *
     * @return array<string, mixed>|object|null Record row structure or null if missing.
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
     *
     * @return int Total number of aggregate rows matching filters.
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
     * Builds the full debugging report for the current query, records it in the
     * request-scoped dumper and lets execution continue. For debugging only.
     *
     * The report is handed to the AsyncDumper instead of the process output:
     * under a persistent runtime (Swoole, RoadRunner) `exit()` raises
     * Swoole\ExitException and unwinds the whole pipeline into a 500, while
     * `echo`/`header()` never reach the Swoole response at all. Routing the
     * report through the dumper is what puts it in the DevTool "Dumps" panel.
     *
     * @return array{sql: string, bindings: array<int, mixed>, compiled: string, explain: array<int, mixed>|null, explain_error: string|null, driver: string, time: float, text: string} Full query report
     */
    public function dd(): array
    {
        $sql      = $this->toSql();
        $bindings = $this->getBindings();

        $explain      = null;
        $explainError = null;

        if ($this->isReadOnlyStatement($sql)) {
            try {
                $explain = $this->connection->explain($sql, $bindings);
            } catch (\Throwable $e) {
                $explainError = $e->getMessage();
            }
        } else {
            // ANALYZE executes the statement, so explaining a write would apply it.
            $explainError = 'EXPLAIN skipped: statement is not read-only.';
        }

        $report = [
            'sql'           => $sql,
            'bindings'      => $bindings,
            'compiled'      => $this->interpolateBindings($sql, $bindings),
            'explain'       => $explain,
            'explain_error' => $explainError,
            'driver'        => $this->connection->getDriver()->getName(),
            'time'          => microtime(true),
        ];

        $report['text'] = $this->formatQueryDump($sql, $bindings, $explain, $explainError);

        $this->recordReport($report);

        return $report;
    }

    /**
     * Hands the report to the request-scoped dumper when one is available.
     *
     * @param array<string, mixed> $report Report produced by dd()
     * @return void
     */
    private function recordReport(array $report): void
    {
        try {
            $container = Container::getInstance();

            if ($container === null) {
                return;
            }

            $container->get(AsyncDumper::class)->dump($report, 'query', 'query');
        } catch (\Throwable) {
            // A debug helper must never take the request down with it.
        }
    }

    /**
     * Whether the statement is safe to hand to the server's EXPLAIN/ANALYZE.
     *
     * @param string $sql Compiled SQL
     * @return bool True for read-only statements
     */
    private function isReadOnlyStatement(string $sql): bool
    {
        $keyword = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');

        return in_array($keyword, ['SELECT', 'WITH'], true);
    }

    /**
     * Returns the EXPLAIN output for the current query without executing it.
     *
     * @return array<int, mixed> EXPLAIN result rows
     * @throws \RuntimeException If EXPLAIN is not supported by the driver
     */
    public function explain(): array
    {
        return $this->connection->explain($this->toSql(), $this->getBindings());
    }

    /**
     * Formats query dump with bindings for display.
     *
     * @param string $sql Query with placeholders
     * @param array<int, mixed> $bindings Parameter bindings
     * @param array<int, mixed>|null $explain EXPLAIN rows when the server provided them
     * @param string|null $explainError EXPLAIN failure message when it did not
     * @return string Human readable report
     */
    private function formatQueryDump(string $sql, array $bindings, ?array $explain = null, ?string $explainError = null): string
    {
        $lines = [];
        $lines[] = '=== QUERY DUMP ===';
        $lines[] = 'SQL: ' . $sql;
        $lines[] = '';

        if ($bindings !== []) {
            $lines[] = 'Bindings:';
            foreach ($bindings as $index => $binding) {
                $type = get_debug_type($binding);
                $value = is_scalar($binding) ? var_export($binding, true) : get_debug_type($binding);
                $lines[] = sprintf('  [%d] (%s) %s', $index, $type, $value);
            }
        } else {
            $lines[] = 'Bindings: (none)';
        }

        $lines[] = '';
        $lines[] = '=== Compiled SQL (with bindings inline) ===';
        $lines[] = $this->interpolateBindings($sql, $bindings);

        if ($explain !== null) {
            $lines[] = '';
            $lines[] = '=== EXPLAIN ===';
            $lines[] = $this->formatExplainOutput($explain);
        }

        if ($explainError !== null) {
            $lines[] = '';
            $lines[] = '=== EXPLAIN not available ===';
            $lines[] = $explainError;
        }

        return implode("\n", $lines);
    }

    /**
     * Interpolates bindings into SQL for display purposes only.
     */
    private function interpolateBindings(string $sql, array $bindings): string
    {
        $result = $sql;
        foreach ($bindings as $binding) {
            $placeholder = '?';
            $pos = strpos($result, $placeholder);
            if ($pos !== false) {
                $quoted = $this->quoteValue($binding);
                $result = substr_replace($result, $quoted, $pos, strlen($placeholder));
            }
        }
        return $result;
    }

    /**
     * Quotes a value for SQL display.
     */
    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * Formats EXPLAIN output for display.
     *
     * @param array<int, mixed> $rows EXPLAIN result rows
     * @return string Table rendering of the plan
     */
    private function formatExplainOutput(array $rows): string
    {
        if ($rows === []) {
            return '(empty result)';
        }

        // Column headers, and their widths keyed by header name so the padding
        // lookups below cannot miss. (A list here reads every width as undefined.)
        $headers = array_map('strval', array_keys($rows[0]));

        $widths = [];
        foreach ($headers as $header) {
            $widths[$header] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($headers as $header) {
                $widths[$header] = max($widths[$header], strlen((string)($row[$header] ?? '')));
            }
        }

        $lines = [];

        // Header
        $headerLine = '';
        foreach ($headers as $header) {
            $headerLine .= str_pad($header, $widths[$header] + 2);
        }
        $lines[] = $headerLine;
        $lines[] = str_repeat('-', strlen($headerLine));

        // Rows
        foreach ($rows as $row) {
            $rowLine = '';
            foreach ($headers as $header) {
                $rowLine .= str_pad((string)($row[$header] ?? ''), $widths[$header] + 2);
            }
            $lines[] = $rowLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Inserts a record into the database table.
     *
     * @param array<string, mixed> $values Key-value map of column values to insert.
     * @return bool True on success.
     * @throws InvalidArgumentException If no table was defined.
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
     * @param array<string, mixed> $values Key-value map of column updates.
     * @return int Affected rows count.
     * @throws InvalidArgumentException If no table was defined.
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
     * @throws InvalidArgumentException If no table was defined.
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
     *
     * @param string $value Unquoted identifier string.
     * @return string Driver-quoted identifier string.
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