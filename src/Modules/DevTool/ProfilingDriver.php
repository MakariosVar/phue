<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use PDO;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Abstract\PdoDriverInterface;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;

/**
 * Class ProfilingDriver
 *
 * Records every statement the framework sends to the database, so the debug toolbar can
 * show which queries a request actually ran and what each one cost.
 *
 * Decorates rather than subclasses because the driver already sits behind
 * DriverInterface. That leaves the database core untouched and lets the same decorator
 * serve the PDO drivers and the HTTP-backed ones (Neon, Turso) without knowing which is in
 * use.
 *
 * Three details matter more than the timing itself:
 *
 *  - Row counts are captured on the *statement*, not on the query. A driver reports
 *    "executed" the moment the statement is prepared; the cost a developer feels is the
 *    fetch. Measuring the wrong one points at the wrong line.
 *  - Bound values never appear. The framework prepares and executes separately, so a
 *    prepared statement carries placeholders. What can leak is a statement assembled by
 *    interpolation, which is why quoted literals are redacted by default.
 *  - Transaction control is measured. A slow commit is otherwise charged to whichever
 *    statement happened to run last.
 *
 * A fourth one is about what the decorator must NOT change. Instrumentation is a
 * transparent layer: callers branch on the optional driver interfaces (PdoDriverInterface)
 * to reach a raw PDO handle, and a decorator that implements only the base contract makes
 * every one of those branches take the "no handle here" path while it is switched on.
 * Use {@see self::wrap()} rather than the constructor, so the decorator keeps whatever the
 * real driver could do.
 *
 * @package Phue\Modules\DevTool
 */
class ProfilingDriver implements DriverInterface
{
    /**
     * @param DriverInterface $inner Real driver performing the work.
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @param bool $redactLiterals Whether quoted literals and comments are removed from
     *        recorded SQL. See {@see self::redact()} for why this is the default.
     */
    public function __construct(
        protected DriverInterface $inner,
        private RequestProfiler $profiler,
        private bool $redactLiterals = true
    ) {}

    /**
     * Decorates a driver, keeping every capability the real driver had.
     *
     * PHP settles an object's interfaces when its class is declared, so a decorator
     * cannot hand back an instance that implements PdoDriverInterface only when the
     * driver behind it does — hence one of two types rather than one type with a switch
     * inside. The alternative, a decorator implementing every optional interface and
     * throwing from the ones it cannot honour, advertises handles it does not have:
     * `instanceof PdoDriverInterface` answers true, the caller asks for the handle, and
     * the failure moves one layer further from its cause.
     *
     * @param DriverInterface $inner Real driver performing the work.
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @param bool $redactLiterals Whether quoted literals are redacted.
     * @return DriverInterface Decorator implementing the optional interfaces $inner has.
     */
    public static function wrap(
        DriverInterface $inner,
        RequestProfiler $profiler,
        bool $redactLiterals = true
    ): DriverInterface {
        if (!$inner instanceof PdoDriverInterface) {
            return new self($inner, $profiler, $redactLiterals);
        }

        return new class ($inner, $profiler, $redactLiterals) extends ProfilingDriver implements PdoDriverInterface {
            /**
             * The handle the real driver holds, not a second one.
             *
             * Two handles would mean two databases for an in-memory driver and two
             * transaction scopes everywhere else: DDL run on this one while statements go
             * through the other would apply to nothing. Not measured, either — reaching
             * for the handle is not a query, and a span per access would bury the report.
             *
             * @return PDO
             */
            public function getPdo(): PDO
            {
                return $this->inner->getPdo();
            }
        };
    }

    /**
     * Prepare and execute a statement, returning a profiling statement wrapper.
     *
     * @param string $sql Statement with placeholders.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return StatementInterface
     */
    public function query(string $sql, array $params = []): StatementInterface
    {
        $statement = $this->profiler->measure(
            'db.query',
            fn(): StatementInterface => $this->inner->query($sql, $params),
            $this->describe($sql, count($params))
        );

        return $this->wrapStatement($statement, $sql);
    }

    /**
     * Execute a modifying statement, recording its affected-row count.
     *
     * @param string $sql Statement with placeholders.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return int
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->profiler->measure(
            'db.execute',
            fn(): int => $this->inner->execute($sql, $params),
            $this->describe($sql, count($params)),
            static fn(int $affected): array => ['rows' => $affected]
        );
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        return $this->profiler->measure(
            'db.beginTransaction',
            fn(): bool => $this->inner->beginTransaction()
        );
    }

    public function commit(): bool
    {
        return $this->profiler->measure(
            'db.commit',
            fn(): bool => $this->inner->commit()
        );
    }

    public function rollBack(): bool
    {
        return $this->profiler->measure(
            'db.rollBack',
            fn(): bool => $this->inner->rollBack()
        );
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function explain(string $sql, array $params = []): array
    {
        return $this->profiler->measure(
            'db.explain',
            fn(): array => $this->inner->explain($sql, $params),
            $this->describe($sql, count($params))
        );
    }

    /**
     * Wrap a statement so its consumption is measured too.
     *
     * @param StatementInterface $statement Statement returned by the inner driver.
     * @param string $sql Statement text, carried into the fetch spans.
     * @return StatementInterface
     */
    private function wrapStatement(StatementInterface $statement, string $sql): StatementInterface
    {
        $profiler = $this->profiler;
        $meta = $this->describe($sql, 0);
        $rowsReported = false;

        return new class ($statement, $profiler, $meta, $rowsReported) implements StatementInterface {
            /**
             * @param StatementInterface $inner Real statement.
             * @param RequestProfiler $profiler Collector receiving the spans.
             * @param array<string, mixed> $meta SQL metadata carried into the fetch spans.
             * @param bool $rowsReported Whether a row total was already surfaced.
             */
            public function __construct(
                private StatementInterface $inner,
                private RequestProfiler $profiler,
                private array $meta,
                private bool $rowsReported
            ) {}

            public function fetch(): array|false
            {
                return $this->profiler->measure(
                    'db.fetch',
                    fn(): array|false => $this->inner->fetch(),
                    $this->meta
                );
            }

            public function fetchAll(): array
            {
                return $this->profiler->measure(
                    'db.fetch',
                    fn(): array => $this->inner->fetchAll(),
                    $this->meta,
                    function (array $rows): array {
                        // Only the first fetch reports the total: a second fetch on the same
                        // statement legitimately returns nothing, and reporting "0 rows"
                        // for it would make a healthy query look like an empty one.
                        if ($this->rowsReported) {
                            return [];
                        }

                        $this->rowsReported = true;

                        return ['rows' => count($rows)];
                    }
                );
            }

            public function fetchColumn(int $columnIndex = 0): mixed
            {
                return $this->profiler->measure(
                    'db.fetch',
                    fn(): mixed => $this->inner->fetchColumn($columnIndex),
                    $this->meta
                );
            }

            public function rowCount(): int
            {
                return $this->profiler->measure(
                    'db.rowCount',
                    fn(): int => $this->inner->rowCount(),
                    $this->meta,
                    static fn(int $count): array => ['rows' => $count]
                );
            }
        };
    }

    /**
     * Build the span metadata for a statement.
     *
     * @param string $sql Statement text.
     * @param int $bindings Number of bound parameters.
     * @return array<string, mixed>
     */
    private function describe(string $sql, int $bindings): array
    {
        return [
            'sql' => $this->redactLiterals ? self::redact($sql) : $sql,
            'bindings' => $bindings,
            'redacted' => $this->redactLiterals,
        ];
    }

    /**
     * Remove quoted literals and comments from a statement.
     *
     * A prepared statement carries only placeholders, so anything quoted in the recorded
     * text arrived through interpolation — the one path that can carry a real row of user
     * data into a page. The structure of the statement survives, so the query stays
     * diagnosable.
     *
     * @param string $sql Statement text.
     * @return string
     */
    public static function redact(string $sql): string
    {
        $patterns = [
            '/\'(?:[^\'\\\\]|\\\\.)*\'/' => '?',
            '/"(?:[^"\\\\]|\\\\.)*"/' => '?',
            '/--[^\n]*/' => '--',
            '#/\*.*?\*/#s' => '/*…*/',
        ];

        $redacted = $sql;
        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $redacted);
            if (is_string($result)) {
                $redacted = $result;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $redacted));
    }
}