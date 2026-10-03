<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Tests\Core\Stubs\PhueJsRunner;
use PHPUnit\Framework\TestCase;

/**
 * Executes the Bun behavioural drivers for the shared Phue JS composables.
 *
 * Each driver imports the real module from `src/Core/UI/js/` and asserts its
 * observable behaviour against a minimal DOM double, so these tests cover
 * runtime semantics (focus containment, inert bookkeeping, geometry, store
 * lifecycles) rather than source shape.
 *
 * The case-count ratchet in PhueJsRunner guards against a driver that stops
 * registering assertions and passes vacuously; raise the floor whenever a
 * driver gains cases, never lower it.
 */
final class PhuiComposableBehaviourTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function driverProvider(): array
    {
        return [
            'overlay focus trap' => ['overlay.test.mjs', 'focus trap / inert', 70],
            'position geometry' => ['position.test.mjs', 'positioning', 98],
            'toast + confirm stores' => ['store.test.mjs', 'shared stores', 140],
        ];
    }

    /**
     * @dataProvider driverProvider
     */
    public function testComposableBehaviourHolds(string $driver, string $context, int $minimum): void
    {
        PhueJsRunner::skipWithoutBun($this, $context);

        $result = PhueJsRunner::run($this, $driver, $context);

        $this->assertSame(
            0,
            $result['exit'],
            "[{$context}] driver exited with {$result['exit']}:\n{$result['raw']}"
        );

        PhueJsRunner::assertAllCasesPassed($this, $result['report'], $minimum, $context);
    }

    /**
     * Guards the plumbing itself: every driver that the provider advertises must
     * exist on disk. Without this, a renamed driver would only surface as a skip.
     */
    public function testEveryAdvertisedDriverExists(): void
    {
        foreach (array_keys(self::driverProvider()) as $label) {
            $this->assertIsString($label);
        }

        $expected = ['overlay.test.mjs', 'position.test.mjs', 'store.test.mjs'];

        foreach ($expected as $driver) {
            $this->assertFileExists(
                PhueJsRunner::driverPath($driver),
                "advertised Bun driver {$driver} is missing from tests/Core/UI/js"
            );
        }
    }

    /**
     * The drivers must stay pure ESM that Bun can execute directly: any import
     * from a framework runtime, or a stray dependency on the DOM being present
     * at module scope, would break both the driver harness and the shipped
     * inline prelude.
     */
    public function testDriversResolveTheRealComposableDirectory(): void
    {
        $jsDir = PhueJsRunner::composableJsDir();

        $this->assertDirectoryExists($jsDir);
        $this->assertFileExists($jsDir . '/overlay.js');
        $this->assertFileExists($jsDir . '/position.js');
        $this->assertFileExists($jsDir . '/store.js');

        // The DOM double is test infrastructure, not shipped source.
        $this->assertFileExists(PhueJsRunner::driverPath('dom-double.mjs'));
        $this->assertFileDoesNotExist($jsDir . '/dom-double.mjs');
    }
}