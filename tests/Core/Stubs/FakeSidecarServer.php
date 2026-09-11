<?php

/**
 * Standalone entrypoint that runs the FakeSidecar as a background server.
 *
 * Mirrors `ssr/server.ts` being "up": binds the given socket and serves
 * Contract 3.2 requests until the process is terminated. Used by
 * PhueSsrClientTest (via proc_open) so the client under test performs real
 * blocking socket I/O against a responding double — without the Bun runtime.
 *
 * This file lives in tests/Core/Stubs, which phpunit.xml excludes from the
 * test suite, so it is never loaded as a test class.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Phue\Tests\Core\Stubs\FakeSidecar;

if (realpath((string) ($_SERVER['argv'][0] ?? '')) !== __FILE__) {
    return;
}

$socketPath = $argv[1] ?? '/tmp/phue-ssr-fake-sidecar.sock';

$sidecar = new FakeSidecar($socketPath);

for (;;) {
    $sidecar->acceptOnce();
}