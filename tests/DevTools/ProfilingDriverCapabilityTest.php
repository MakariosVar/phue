<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Abstract\PdoDriverInterface;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;
use Phue\Modules\DevTool\ProfilingDriver;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;

/**
 * A driver that offers the base contract and nothing else: no PDO handle, no name beyond
 * the contract. Used to prove the decorator does not grant capabilities the inner driver
 * never had.
 */
final class CapabilityLessDriver implements DriverInterface
{
    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function query(string $sql, array $params = []): StatementInterface
    {
        return new CapabilityLessStatement();
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '0';
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function getName(): string
    {
        return 'capabilityless';
    }

    public function explain(string $sql, array $params = []): array
    {
        return [];
    }
}

final class CapabilityLessStatement implements StatementInterface
{
    public function fetch(): array|false
    {
        return false;
    }

    public function fetchAll(): array
    {
        return [];
    }

    public function fetchColumn(int $columnIndex = 0): mixed
    {
        return null;
    }

    public function rowCount(): int
    {
        return 0;
    }
}

/**
 * What decoration is allowed to change, and what it is not.
 *
 * A decorator that keeps the base contract is a decorator that can still be dropped in
 * front of anything. A decorator that also erases the optional capabilities is not: code
 * that asked the driver for something the base contract does not mention — a PDO handle,
 * a dialect detail — stops working the moment the profiler is switched on, and the
 * failure surfaces far from its cause (a schema diff endpoint reporting that a decorator
 * "does not have a method", for instance).
 *
 * The test below runs against the real SQLite driver rather than a double, because the
 * capability under test is the one the concrete driver genuinely provides.
 */
#[Group('integration')]
final class ProfilingDriverCapabilityTest extends TestCase
{
    private SqliteDriver $inner;
    private PDO $pdo;
    private RequestProfiler $profiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = new SqliteDriver([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Connected here so the handle exists before it is compared: a driver that opens
        // one lazily would otherwise hand out a different instance per call and the
        // identity assertion would be meaningless.
        $this->pdo = $this->inner->getPdo();

        $this->profiler = new RequestProfiler(new Stopwatch());
        $this->profiler->start();
    }

    private function decorate(DriverInterface $inner): DriverInterface
    {
        return ProfilingDriver::wrap($inner, $this->profiler);
    }

    /**
     * The decorator narrowed to the capability under test.
     *
     * wrap() answers with the base contract, because that is all a caller can know before
     * asking — the narrowing is the caller's move, and the interface check is how they
     * make it.
     */
    private function pdoDecorator(): PdoDriverInterface
    {
        $decorator = $this->decorate($this->inner);

        assert($decorator instanceof PdoDriverInterface);

        return $decorator;
    }

    public function testPdoCapabilitySurvivesDecoration(): void
    {
        // Consumers do not reach for a method they know exists by accident: Schii's schema
        // inspector, the health check and the migrator all branch on this interface before
        // touching a raw handle.
        $this->assertInstanceOf(PdoDriverInterface::class, $this->decorate($this->inner));
    }

    public function testDecoratedDriverReturnsTheInnerPdoInstance(): void
    {
        // Same instance, not an equivalent one. A decorator that opened its own handle
        // would give the caller a different database, and for :memory: a different empty
        // one.
        $this->assertSame($this->pdo, $this->pdoDecorator()->getPdo());
    }

    public function testHandedOutHandleIsTheOneStatementsRunOn(): void
    {
        $decorator = $this->pdoDecorator();

        $decorator->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $decorator->execute("INSERT INTO widgets (name) VALUES ('a')");

        $seen = $decorator->getPdo()->query('SELECT name FROM widgets')->fetchAll();

        $this->assertSame([['name' => 'a']], $seen);
    }

    public function testHandedOutHandleSharesAnOpenTransaction(): void
    {
        // SchemaSyncEngine::apply() runs its DDL on the raw handle inside its own
        // transaction. A second handle would commit that transaction on a different
        // connection and quietly split the work in two.
        $decorator = $this->pdoDecorator();

        $decorator->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $decorator->beginTransaction();
        $decorator->getPdo()->exec("INSERT INTO widgets (name) VALUES ('doomed')");
        $decorator->rollBack();

        $this->assertSame([], $decorator->getPdo()->query('SELECT name FROM widgets')->fetchAll());
    }

    public function testPdoAwareDecoratorStillMeasures(): void
    {
        // The capability-preserving branch has to be a full decorator, not a pass-through
        // added as an apology: a driver that reports nothing would make the toolbar lie in
        // exactly the environment where it is switched on.
        $this->pdoDecorator()->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $this->assertSame('db.execute', $this->profiler->getSpans()[0]['label']);
    }

    public function testHandingOutTheHandleIsNotMeasured(): void
    {
        // Same rule as isConnected() and getName(): reaching for the handle is not a query,
        // and a span per access would bury the report it is supposed to explain.
        $this->pdoDecorator()->getPdo();

        $this->assertSame([], $this->profiler->getSpans());
    }

    public function testDriverWithoutTheCapabilityIsNotPromotedToHavingIt(): void
    {
        // The other half of the contract. A decorator that implements every optional
        // interface unconditionally would advertise a handle it cannot produce, and the
        // failure would move from "method missing" to "returns null" further down.
        $decorator = $this->decorate(new CapabilityLessDriver());

        $this->assertNotInstanceOf(PdoDriverInterface::class, $decorator);
        $this->assertSame('capabilityless', $decorator->getName());
    }
}
