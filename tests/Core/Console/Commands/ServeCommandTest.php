<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Integration test booting the real PHP built-in web server via ./phue serve.
 *
 * Exercises the actual ServeCommand flow end-to-end: argument parsing, doc-root
 * resolution, process launch and HTTP reachability.
 *
 * @package Phue\Tests\Core\Console\Commands
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class ServeCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/phue_serve_test_ws';
        $this->removeDir($this->workDir);
        mkdir($this->workDir . '/public', 0777, true);
        file_put_contents(
            $this->workDir . '/public/index.php',
            '<?php echo "SERVE" . (string)(2 + 2);'
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
        parent::tearDown();
    }

    public function testServeBootsBuiltInServerAndServesRequest(): void
    {
        $port = $this->findFreePort();
        $phueBin = dirname(__DIR__, 4) . '/bin/phue';

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $phueBin, 'serve:php', '--host=127.0.0.1', "--port={$port}"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workDir,
            ['PATH' => (string) getenv('PATH')]
        );

        try {
            $this->assertIsResource($process, 'Failed to spawn the phue serve subprocess.');
            $this->assertArrayHasKey(1, $pipes, 'stdout pipe expected from proc_open.');
            $this->assertArrayHasKey(2, $pipes, 'stderr pipe expected from proc_open.');
            $this->assertIsResource($pipes[1]);
            $this->assertIsResource($pipes[2]);

            $socket = $this->waitForServer($port);
            if ($socket === false || $socket === null) {
                // Only read the pipes here: on failure the banner has no chance to
                // have been emitted yet, and reading them is our only diagnostic.
                $this->fail($this->subprocessDiagnostics($pipes));
            }

            fwrite($socket, "GET / HTTP/1.0\r\n\r\n");
            $response = stream_get_contents($socket);
            fclose($socket);

            $this->assertStringContainsString('200 OK', $response);
            $this->assertStringContainsString('SERVE4', $response);

            $banner = $this->waitForBanner($pipes);
            $this->assertStringContainsString(
                'Phue Development Server started',
                $banner,
                'ServeCommand banner expected on stdout. ' . $this->pipeDump($pipes)
            );
        } finally {
            $this->stopServer($process, $pipes, $port);
        }
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "Unable to allocate a free port: {$errstr}");

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * Polls the target port until the built-in server accepts connections.
     *
     * @return resource|null
     */
    private function waitForServer(int $port)
    {
        $deadline = microtime(true) + 20.0;

        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($socket !== false) {
                return $socket;
            }
            usleep(150_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Reads the child stdout pipe (non-blocking) until the startup banner shows
     * up or the timeout is reached.
     *
     * @param array<int, resource> $pipes pipe handles as returned by proc_open
     */
    private function waitForBanner(array $pipes): string
    {
        if (!isset($pipes[1]) || !is_resource($pipes[1])) {
            return '';
        }

        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 10.0;
        $collected = '';

        do {
            $chunk = (string) stream_get_contents($pipes[1]);
            if ($chunk !== '') {
                $collected .= $chunk;
            }
            if (str_contains($collected, 'Phue Development Server started')) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $collected;
    }

    /**
     * @param array<int, resource> $pipes pipe handles as returned by proc_open
     */
    private function subprocessDiagnostics(array $pipes): string
    {
        return 'Server never accepted connections. ' . $this->pipeDump($pipes);
    }

    /**
     * @param array<int, resource> $pipes pipe handles as returned by proc_open
     */
    private function pipeDump(array $pipes): string
    {
        $dump = '';
        foreach ([1 => 'STDOUT', 2 => 'STDERR'] as $idx => $label) {
            if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
                stream_set_blocking($pipes[$idx], false);
                $dump .= $label . ': [' . bin2hex((string) stream_get_contents($pipes[$idx])) . '] ';
            }
        }

        return $dump;
    }

    /**
     * @param array<int, resource> $pipes pipe handles as returned by proc_open
     */
    private function stopServer($process, array $pipes, int $port): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            usleep(100_000);
        }

        // proc_terminate only signals the direct child; the passthru-spawned
        // `php -S` grandchild keeps running and would hold the port. Scan
        // /proc until nothing matching our port remains; retry a few times so
        // both the shell wrapper and the real server are caught regardless of
        // their command-line quoting.
        if ($port > 0) {
            $portNeedle = ':' . $port;
            $deadline = microtime(true) + 2.0;

            do {
                $killed = false;

                foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
                    $cmdline = @file_get_contents($cmdlineFile) ?: '';
                    // The real `php -S` argv is NUL-separated ("php\0-S\0..."),
                    // while the passthru shell wrapper uses spaces in one string;
                    // match only on the unique port so both forms are covered.
                    if (!str_contains($cmdline, $portNeedle)) {
                        continue;
                    }

                    $pid = (int) basename(dirname($cmdlineFile));
                    if ($pid > 0 && $pid !== getmypid()) {
                        $killed = true;
                        if (function_exists('posix_kill')) {
                            @posix_kill($pid, 9);
                        } else {
                            @exec('kill -9 ' . $pid);
                        }
                    }
                }

                usleep(50_000);
            } while ($killed && microtime(true) < $deadline);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_close($process);
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