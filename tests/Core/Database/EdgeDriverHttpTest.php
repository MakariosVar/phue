<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\NeonDriver;
use Phue\Core\Database\Drivers\TursoDriver;
use RuntimeException;

/**
 * Integration tests driving TursoDriver and NeonDriver against a REAL HTTP
 * server (php -S) that mimics the Neon /sql and Turso /v2/pipeline APIs.
 *
 * The router script is generated into a temp dir at runtime so no scratch
 * files pollute the repository. Scenarios are triggered by a prefix in the
 * SQL/query text the driver sends:
 *   - "FAIL"   -> the server answers with HTTP 500
 *   - "CAPTURE"-> the server echoes back what the driver actually sent
 *   - "EMPTY"  -> the server answers with an empty/missing result payload
 *   - "EXEC"   -> (Turso only) affected_row_count response
 *   - default  -> a regular SELECT result payload
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class EdgeDriverHttpTest extends TestCase
{
    private string $tmpDir;
    private int $port = 0;
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_edge_http_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/router.php', $this->routerScript());

        $this->port = $this->findFreePort();
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->tmpDir, $this->tmpDir . '/router.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            $this->tmpDir,
            ['PATH' => (string) getenv('PATH')]
        );

        $this->assertIsResource($this->process, 'Failed to spawn the fake HTTP SQL server subprocess.');
        $this->assertTrue($this->waitForHttpServer($this->port), 'Fake HTTP server never became reachable.');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // TursoDriver against the fake /v2/pipeline endpoint
    // ------------------------------------------------------------------

    public function testTursoQueryParsesRowsIntoMappedArrays(): void
    {
        $driver = new TursoDriver('http://127.0.0.1:' . $this->port, 'token');
        $rows = $driver->query('SELECT id, name FROM t');

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'ts'],
                ['id' => 2, 'name' => 'tu'],
            ],
            $rows
        );
    }

    public function testTursoQueryReturnsEmptyArrayWhenResultMissing(): void
    {
        $driver = new TursoDriver('http://127.0.0.1:' . $this->port, 'token');
        $this->assertSame([], $driver->query('EMPTY SELECT 1'));
    }

    public function testTursoExecuteReturnsAffectedRowCount(): void
    {
        $driver = new TursoDriver('http://127.0.0.1:' . $this->port, 'token');
        $affected = $driver->execute('EXEC INSERT INTO t VALUES (1)');

        $this->assertSame(3, $affected);
    }

    public function testTursoFormatsParamsByPhpType(): void
    {
        $driver = new TursoDriver('http://127.0.0.1:' . $this->port, 'token');
        $rows = $driver->query('CAPTURE SELECT ?', [1, 1.5, null, 'x']);

        $this->assertSame(
            [
                ['type' => 'integer', 'value' => '1'],
                ['type' => 'float', 'value' => 1.5],
                ['type' => 'null'],
                ['type' => 'text', 'value' => 'x'],
            ],
            json_decode($rows[0]['args'], true)
        );
    }

    public function testTursoThrowsRuntimeExceptionOnHttpError(): void
    {
        $driver = new TursoDriver('http://127.0.0.1:' . $this->port, 'token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Turso HTTP request failed with code 500');

        $driver->query('FAIL SELECT 1');
    }

    // ------------------------------------------------------------------
    // NeonDriver against the fake /sql endpoint
    // ------------------------------------------------------------------

    public function testNeonQueryReturnsRows(): void
    {
        $driver = new NeonDriver('http://127.0.0.1:' . $this->port . '/sql', 'token');
        $rows = $driver->query('SELECT id, name FROM t');

        $this->assertSame(
            [
                ['id' => 1],
            ],
            $rows
        );
    }

    public function testNeonQueryReturnsEmptyWhenRowsKeyMissing(): void
    {
        $driver = new NeonDriver('http://127.0.0.1:' . $this->port . '/sql', 'token');
        $this->assertSame([], $driver->query('EMPTY SELECT 1'));
    }

    public function testNeonExecuteReturnsRowCount(): void
    {
        $driver = new NeonDriver('http://127.0.0.1:' . $this->port . '/sql', 'token');
        $this->assertSame(2, $driver->execute('INSERT INTO t VALUES (1)'));
    }

    public function testNeonSendsParamsAsPositionalArray(): void
    {
        $driver = new NeonDriver('http://127.0.0.1:' . $this->port . '/sql', 'token');
        $rows = $driver->query('CAPTURE SELECT ?', [7, 'n']);

        $this->assertSame([7, 'n'], $rows[0]['params']);
    }

    public function testNeonThrowsRuntimeExceptionOnHttpError(): void
    {
        $driver = new NeonDriver('http://127.0.0.1:' . $this->port . '/sql', 'token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Neon HTTP request failed with code 500');

        $driver->query('FAIL SELECT 1');
    }

    // ------------------------------------------------------------------
    // Fake server plumbing
    // ------------------------------------------------------------------

    private function routerScript(): string
    {
        return <<<'PHP'
<?php

header('Content-Type: application/json');

$body = file_get_contents('php://input');
$payload = json_decode($body ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(500);
    echo '{"error":"malformed"}';
    return;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';

// ---- Neon /sql endpoint ----
if (str_ends_with($path, '/sql')) {
    $query = (string) ($payload['query'] ?? '');

    if (str_starts_with($query, 'FAIL')) {
        http_response_code(500);
        echo '{"error":"boom"}';
        return;
    }

    if (str_starts_with($query, 'CAPTURE')) {
        echo json_encode([
            'query' => $query,
            'params' => $payload['params'] ?? [],
            'rows' => [['id' => 1, 'params' => $payload['params'] ?? []]],
        ]);
        return;
    }

    if (str_starts_with($query, 'EMPTY')) {
        echo json_encode(['rowCount' => 0]);
        return;
    }

    echo json_encode(['rows' => [['id' => 1]], 'rowCount' => 2]);
    return;
}

// ---- Turso /v2/pipeline endpoint ----
if (str_contains($path, '/v2/pipeline')) {
    $sql = (string) ($payload['requests'][0]['stmt']['sql'] ?? '');
    $args = (array) ($payload['requests'][0]['stmt']['args'] ?? []);

    if (str_starts_with($sql, 'FAIL')) {
        http_response_code(500);
        echo '{"error":"boom"}';
        return;
    }

    if (str_starts_with($sql, 'CAPTURE')) {
        echo tursoResult([
            'cols' => [['name' => 'args']],
            'rows' => [[['value' => json_encode($args)]]],
        ]);
        return;
    }

    if (str_starts_with($sql, 'EMPTY')) {
        echo json_encode(['results' => [['response' => ['result' => null]]]]);
        return;
    }

    if (str_starts_with($sql, 'EXEC')) {
        echo json_encode(['results' => [['response' => ['result' => ['affected_row_count' => 3]]]]]);
        return;
    }

    echo tursoResult([
        'cols' => [['name' => 'id'], ['name' => 'name']],
        'rows' => [
            [['value' => 1], ['value' => 'ts']],
            [['value' => 2], ['value' => 'tu']],
        ],
    ]);
    return;
}

http_response_code(404);
echo '{"error":"route not found"}';

function tursoResult(array $result): string
{
    return json_encode(['results' => [['response' => ['result' => $result]]]]);
}
PHP;
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "Unable to allocate a free port: {$errstr}");

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function waitForHttpServer(int $port): bool
    {
        $deadline = microtime(true) + 10.0;

        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function stopServer(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            usleep(100_000);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_close($this->process);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($dir);
    }
}