<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

use Phue\Core\Ssr\BunSsrEngine;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\Ssr\UnixSsrClientORT;

// No direct execution guard: this runner is only ever spawned via proc_open() by
// the integration tests and lives in tests/Core/Stubs/ which phpunit.xml excludes
// from the test suite scan.
