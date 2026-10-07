<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Debug;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Debug\AsyncDumper;
use Phue\Core\ResettableInterface;
use stdClass;

/**
 * Unit tests for the request-scoped AsyncDumper.
 *
 * The dumper is the single sink behind the global dump()/dd() helpers and behind
 * the DevTool "Dumps" panel, so every invariant it holds is load-bearing for a
 * persistent runtime: bounded memory, JSON-safe values, a rotating request id
 * and a trace that points at application code instead of framework internals.
 *
 * @package Phue\Tests\Core\Debug
 */
#[Group('unit')]
final class AsyncDumperTest extends TestCase
{
    // ------------------------------------------------------------- recording

    public function testDumpRecordsValueLabelGroupAndTrace(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump(['id' => 1], 'user', 'sql');

        $dumps = $dumper->getDumps();

        $this->assertCount(1, $dumps);
        $this->assertSame(['id' => 1], $dumps[0]['value']);
        $this->assertSame('user', $dumps[0]['label']);
        $this->assertSame('sql', $dumps[0]['group']);
        $this->assertIsFloat($dumps[0]['time']);
        $this->assertIsArray($dumps[0]['trace']);
        $this->assertSame($dumper->getRequestId(), $dumps[0]['request_id']);
    }

    public function testDumpDefaultsToEmptyLabelAndDefaultGroup(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump('value');

        $dumps = $dumper->getDumps();

        $this->assertSame('', $dumps[0]['label']);
        $this->assertSame('default', $dumps[0]['group']);
    }

    public function testDumpPreservesRecordingOrder(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump('first');
        $dumper->dump('second');
        $dumper->dump('third');

        $this->assertSame(
            ['first', 'second', 'third'],
            array_column($dumper->getDumps(), 'value')
        );
    }

    public function testDumpIsFluentAndReturnsSelf(): void
    {
        $dumper = new AsyncDumper();

        $this->assertSame($dumper, $dumper->dump('value'));
    }

    public function testEachInstanceOwnsItsRequestId(): void
    {
        $first  = new AsyncDumper();
        $second = new AsyncDumper();

        $this->assertNotSame($first->getRequestId(), $second->getRequestId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first->getRequestId());
    }

    // ------------------------------------------------------------- capacity

    public function testDumpsAreCappedAtMaxDumps(): void
    {
        $dumper = new AsyncDumper(maxDumps: 2);

        $dumper->dump(1);
        $dumper->dump(2);
        $dumper->dump(3);
        $dumper->dump(4);

        $this->assertCount(2, $dumper->getDumps());
    }

    public function testCappedDumperKeepsTheEarliestDumps(): void
    {
        $dumper = new AsyncDumper(maxDumps: 1);

        $dumper->dump('kept');
        $dumper->dump('dropped');

        $this->assertSame('kept', $dumper->getDumps()[0]['value']);
    }

    // ------------------------------------------------------------- sampling

    public function testZeroSamplingRateRecordsNothing(): void
    {
        $dumper = new AsyncDumper(samplingRate: 0.0);

        $dumper->dump('value');

        $this->assertSame([], $dumper->getDumps());
    }

    public function testFullSamplingRateRecordsEverything(): void
    {
        $dumper = new AsyncDumper(samplingRate: 1.0);

        $dumper->dump('a');
        $dumper->dump('b');

        $this->assertCount(2, $dumper->getDumps());
    }

    public function testDumpIfOnlyRecordsWhenConditionHolds(): void
    {
        $dumper = new AsyncDumper();

        $this->assertSame($dumper, $dumper->dumpIf(false, 'skipped'));
        $dumper->dumpIf(true, 'kept');

        $this->assertSame(['kept'], array_column($dumper->getDumps(), 'value'));
    }

    public function testDumpWhenOnlyRecordsWhenConditionHolds(): void
    {
        $dumper = new AsyncDumper();
        $calls   = 0;

        $dumper->dumpWhen(static function () use (&$calls): bool {
            $calls++;
            return false;
        }, 'skipped');

        $this->assertSame(1, $calls, 'The condition must still be evaluated.');
        $this->assertSame([], $dumper->getDumps());
    }

    public function testDumpSampleZeroRecordsNothing(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dumpSample(0.0, 'value');

        $this->assertSame([], $dumper->getDumps());
    }

    public function testDumpSampleOneRecordsTheValue(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dumpSample(1.0, 'value');

        $this->assertCount(1, $dumper->getDumps());
    }

    // ------------------------------------------------------------- redaction

    public function testSensitiveKeysAreRedacted(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump([
            'id'       => 7,
            'password' => 'hunter2',
            'api_key'  => 'k-123',
            'ssn'      => '123-45-6789',
        ]);

        $value = $dumper->getDumps()[0]['value'];

        $this->assertSame(7, $value['id']);
        $this->assertSame('***REDACTED***', $value['password']);
        $this->assertSame('***REDACTED***', $value['api_key']);
        $this->assertSame('***REDACTED***', $value['ssn']);
    }

    public function testRedactionIsCaseInsensitiveAndMatchesSubstrings(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump([
            'Password'       => 'a',
            'USER_TOKEN'     => 'b',
            'clientSecretId' => 'c',
        ]);

        $value = $dumper->getDumps()[0]['value'];

        $this->assertSame('***REDACTED***', $value['Password']);
        $this->assertSame('***REDACTED***', $value['USER_TOKEN']);
        $this->assertSame('***REDACTED***', $value['clientSecretId']);
    }

    public function testRedactionReachesNestedStructures(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump([
            'user' => [
                'profile' => ['token' => 't-1', 'name' => 'Makarios'],
            ],
        ]);

        $value = $dumper->getDumps()[0]['value'];

        $this->assertSame('***REDACTED***', $value['user']['profile']['token']);
        $this->assertSame('Makarios', $value['user']['profile']['name']);
    }

    public function testRedactionReachesObjectProperties(): void
    {
        $credentials          = new stdClass();
        $credentials->token   = 't-1';
        $credentials->visible = 'ok';

        $dumper = new AsyncDumper();
        $dumper->dump($credentials);

        $value = $dumper->getDumps()[0]['value'];

        $this->assertSame('***REDACTED***', $value['token']);
        $this->assertSame('ok', $value['visible']);
    }

    public function testCustomRedactKeysReplaceTheDefaults(): void
    {
        $dumper = new AsyncDumper(redactKeys: ['session']);

        $dumper->dump(['session' => 's', 'password' => 'p']);

        $value = $dumper->getDumps()[0]['value'];

        $this->assertSame('***REDACTED***', $value['session']);
        $this->assertSame('p', $value['password'], 'Defaults are replaced, not merged.');
    }

    // ------------------------------------------------------------- depth

    public function testDeeplyNestedValuesAreTruncatedAtMaxDepth(): void
    {
        $dumper = new AsyncDumper(maxDepth: 3);

        $value = 'leaf';
        for ($i = 0; $i < 12; $i++) {
            $value = ['level' => $value];
        }

        $dumper->dump($value);

        $encoded = json_encode($dumper->getDumps()[0]['value'], JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('[Max depth reached]', $encoded);
    }

    public function testValueInsideTheDepthLimitIsPreserved(): void
    {
        $dumper = new AsyncDumper(maxDepth: 8);

        $dumper->dump(['a' => ['b' => ['c' => 'deep']]]);

        $this->assertSame('deep', $dumper->getDumps()[0]['value']['a']['b']['c']);
    }

    public function testCyclicObjectGraphTerminates(): void
    {
        $a          = new stdClass();
        $b          = new stdClass();
        $a->peer    = $b;
        $b->peer    = $a;
        $a->name    = 'a';
        $b->name    = 'b';

        $dumper = new AsyncDumper(maxDepth: 4);
        $dumper->dump($a);

        $this->assertCount(1, $dumper->getDumps());
        $this->assertSame('a', $dumper->getDumps()[0]['value']['name']);
    }

    // ------------------------------------------------------------- objects

    public function testDebugInfoIsPreferredOverReflection(): void
    {
        $value = new class () {
            public string $ignored = 'no';

            /**
             * @return array<string, mixed>
             */
            public function __debugInfo(): array
            {
                return ['shown' => 'yes'];
            }
        };

        $dumper = new AsyncDumper();
        $dumper->dump($value);

        $dumped = $dumper->getDumps()[0]['value'];

        $this->assertSame(['shown' => 'yes'], $dumped);
    }

    public function testObjectsAreLabelledWithTheirClassName(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump(new stdClass());

        $this->assertSame('stdClass', $dumper->getDumps()[0]['value']['__class']);
    }

    public function testProtectedPropertiesAreCaptured(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump($this->protectedHolder());

        $dumped = $dumper->getDumps()[0]['value'];

        $this->assertSame('protected-value', $dumped['protectedValue']);
    }

    public function testPrivatePropertiesAreNotCaptured(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump($this->protectedHolder());

        $dumped = $dumper->getDumps()[0]['value'];

        $this->assertArrayNotHasKey('privateValue', $dumped);
    }

    // ------------------------------------------------------------- traces

    public function testTracePointsAtTheCaller(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump('value');

        $frames  = $dumper->getDumps()[0]['trace'];
        $sources = array_column($frames, 'file');

        $this->assertContains(__FILE__, $sources);
    }

    public function testTraceSkipsFrameworkInternalFrames(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump('value');

        foreach ($dumper->getDumps()[0]['trace'] as $frame) {
            $this->assertStringNotContainsString('/src/Core/Debug/', $frame['file']);
            $this->assertStringNotContainsString('/vendor/', $frame['file']);
        }
    }

    public function testTraceFramesCarryFileLineAndFunction(): void
    {
        $dumper = new AsyncDumper();

        $dumper->dump('value');

        $frame = $dumper->getDumps()[0]['trace'][0];

        $this->assertArrayHasKey('file', $frame);
        $this->assertArrayHasKey('line', $frame);
        $this->assertArrayHasKey('function', $frame);
        $this->assertArrayHasKey('class', $frame);
        $this->assertIsInt($frame['line']);
    }

    // ------------------------------------------------------------- lifecycle

    public function testClearRemovesDumpsButKeepsTheRequestId(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump('value');
        $requestId = $dumper->getRequestId();

        $dumper->clear();

        $this->assertSame([], $dumper->getDumps());
        $this->assertSame($requestId, $dumper->getRequestId());
    }

    public function testResetRemovesDumpsAndRotatesTheRequestId(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump('value');
        $requestId = $dumper->getRequestId();

        $dumper->reset();

        $this->assertSame([], $dumper->getDumps());
        $this->assertNotSame($requestId, $dumper->getRequestId());
    }

    public function testDumperIsResettableForPersistentRuntimes(): void
    {
        $this->assertInstanceOf(ResettableInterface::class, new AsyncDumper());
    }

    public function testGetDumpsReturnsASnapshotThatLaterDumpsCannotMutate(): void
    {
        $dumper = new AsyncDumper();
        $dumper->dump('first');

        $snapshot = $dumper->getDumps();
        $dumper->dump('second');

        $this->assertCount(1, $snapshot);
        $this->assertCount(2, $dumper->getDumps());
    }

    // ------------------------------------------------------------- transport

    public function testRecordedDumpsAreJsonEncodable(): void
    {
        $object          = new stdClass();
        $object->id      = 3;
        $object->joined  = '2026-01-01';
        $object->ratio   = 0.5;
        $object->missing = null;

        $dumper = new AsyncDumper();
        $dumper->dump(['object' => $object, 'list' => [1, 2.5, 'x', true, null]], 'mixed');

        $encoded = json_encode($dumper->getDumps()[0]['value'], JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $decoded['object']['id']);
        $this->assertSame(0.5, $decoded['object']['ratio']);
        $this->assertNull($decoded['object']['missing']);
        $this->assertSame([1, 2.5, 'x', true, null], $decoded['list']);
    }

    public function testRecordedDumpsNeverContainObjectInstances(): void
    {
        $connection = new class () {
            public mixed $pdo = null;
        };

        $dumper = new AsyncDumper();
        $dumper->dump(['connection' => $connection]);

        $this->assertSame(
            [],
            $this->collectObjects($dumper->getDumps()[0]['value']),
            'Objects in the dump payload break json_encode in the DevTool panel.'
        );
    }

    /**
     * Collects every object instance reachable from the given value.
     *
     * @param mixed $value Value to walk
     * @return array<int, object> Objects found
     */
    private function collectObjects(mixed $value): array
    {
        if (is_object($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $found = [];
        foreach ($value as $nested) {
            foreach ($this->collectObjects($nested) as $object) {
                $found[] = $object;
            }
        }

        return $found;
    }

    /**
     * Holder used by the protected/private property tests.
     *
     * @return object Object with one protected and one private property
     */
    private function protectedHolder(): object
    {
        return new class () {
            protected string $protectedValue = 'protected-value';

            private string $privateValue = 'private-value';
        };
    }
}