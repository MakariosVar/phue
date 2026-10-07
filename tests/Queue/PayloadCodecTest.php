<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Queue\Exception\QueuePayloadException;
use Phue\Modules\Queue\PayloadCodec;
use Phue\Tests\Queue\Stubs\AsymmetricCodecJob;
use Phue\Tests\Queue\Stubs\CustomCodecJob;
use Phue\Tests\Queue\Stubs\EntityStub;
use Phue\Tests\Queue\Stubs\NoConstructorJob;
use Phue\Tests\Queue\Stubs\NotAJob;
use Phue\Tests\Queue\Stubs\ObjectArgumentJob;
use Phue\Tests\Queue\Stubs\PlainJob;
use Phue\Tests\Queue\Stubs\PrivatePropertyJob;

/**
 * Whether a job can be carried between processes, and refused clearly when it cannot.
 *
 * This is the layer that decides whether writing a job is five lines or twenty, so every test
 * here is really a test about the ergonomics of the contract. The refusals matter as much as the
 * acceptances: a payload the queue cannot carry has to be rejected while the developer is looking
 * at the enqueue site, with a message that says which parameter and which type, because the same
 * mistake discovered in a worker hours later is a stack trace pointing at nothing useful.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class PayloadCodecTest extends TestCase
{
    private PayloadCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new PayloadCodec();
    }

    // ------------------------------------------------------------ what travels by default

    public function testAConstructorArgumentIsCarriedWithoutTheJobDeclaringAnything(): void
    {
        $payload = $this->codec->encode(new PlainJob('welcome', 3));

        $this->assertSame(['subject' => 'welcome', 'count' => 3], $payload);
    }

    public function testAJobWithNoStateCarriesNothing(): void
    {
        $this->assertSame([], $this->codec->encode(new NoConstructorJob()));
    }

    public function testAJobIsRebuiltFromTheSameValues(): void
    {
        $original = new PlainJob('receipt', 9);
        $payload = $this->codec->encode($original);

        $rebuilt = $this->codec->decode(PlainJob::class, $payload);

        $this->assertInstanceOf(PlainJob::class, $rebuilt);
        $this->assertSame('receipt', $rebuilt->subject);
        $this->assertSame(9, $rebuilt->count);
    }

    /**
     * The whole point of deriving the payload from the constructor: a round trip through
     * serialize/unserialize would not survive this comparison, and neither would a hand-written
     * pair the author forgot to update.
     */
    public function testARoundTripPreservesEveryConstructorArgument(): void
    {
        $job = new PlainJob('ünïcödé: sl/ashes', -7);

        $rebuilt = $this->codec->decode(PlainJob::class, $this->codec->encode($job));

        $this->assertSame($job->subject, $rebuilt->subject);
        $this->assertSame($job->count, $rebuilt->count);
    }

    // ------------------------------------------------------------ what is refused, and how

    public function testAnObjectArgumentIsRefusedWithTheParameterAndTypeNamed(): void
    {
        $job = new ObjectArgumentJob(new EntityStub(7));

        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches(
            '/ObjectArgumentJob::__construct parameter \$user expects .*EntityStub but was given '
            . '.*EntityStub\./'
        );

        $this->codec->encode($job);
    }

    /**
     * The message has to tell the developer what to do, not only what is wrong. "Pass $user->id"
     * is the entire fix, and it is the sentence that saves the twenty minutes this error
     * otherwise costs.
     */
    public function testTheRefusalSaysHowToFixIt(): void
    {
        try {
            $this->codec->encode(new ObjectArgumentJob(new EntityStub(1)));
            $this->fail('Expected a QueuePayloadException.');
        } catch (QueuePayloadException $e) {
            $this->assertStringContainsString('$user->id', $e->getMessage());
            $this->assertStringContainsString('toPayload()', $e->getMessage());
        }
    }

    public function testARequiredArgumentWithNoReadablePropertyIsRefused(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/PrivatePropertyJob.*\$token.*no matching property/s');

        $this->codec->encode(new PrivatePropertyJob('secret'));
    }

    public function testAPrivatePropertyIsNotReachedInto(): void
    {
        // Deliberately asserting the refusal rather than the reflection: setAccessible() would
        // work here, and would surprise the author of every job in the codebase whose constructor
        // arguments are not properties by design.
        $this->expectException(QueuePayloadException::class);

        $this->codec->encode(new PrivatePropertyJob('secret'));
    }

    // ------------------------------------------------------------ custom encoders

    public function testAPairOfOverridesIsUsedInBothDirections(): void
    {
        $job = CustomCodecJob::make('abc');

        $payload = $this->codec->encode($job);
        $this->assertSame(['code' => 'abc'], $payload);

        $rebuilt = $this->codec->decode(CustomCodecJob::class, $payload);
        $this->assertInstanceOf(CustomCodecJob::class, $rebuilt);
        $this->assertSame('abc', $rebuilt->code);
    }

    public function testAnEncoderWithNoDecoderIsRefusedAtPushTime(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/implements toPayload\(\) but not fromPayload\(\)/');

        $this->codec->decode(AsymmetricCodecJob::class, ['value' => 'x']);
    }

    /**
     * Refused at encode as well as decode. Encoding is the half the developer is present for, and
     * a job that can be pushed but never run is the worst possible time to find out.
     */
    public function testAnEncoderWithNoDecoderIsRefusedBeforeTheJobIsEvenStored(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/implements toPayload\(\) but not fromPayload\(\)/');

        $this->codec->encode(new AsymmetricCodecJob());
    }

    // ------------------------------------------------------------ unknown or wrong classes

    public function testAnUnknownClassIsRefused(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/does not exist or is not autoloadable/');

        $this->codec->decode('Phue\\Modules\\Queue\\ThereIsNoSuchJob', []);
    }

    public function testAClassThatIsNotAJobIsRefused(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/does not implement .*JobInterface/');

        $this->codec->decode(NotAJob::class, ['value' => 'x']);
    }

    /**
     * newInstanceArgs() with a short array fails on whichever parameter happens to be required
     * first, which is not necessarily the one that was forgotten. Naming all of them turns a
     * confusing type error into an obvious answer.
     */
    public function testAPayloadMissingARequiredArgumentNamesEveryMissingOne(): void
    {
        try {
            $this->codec->decode(PlainJob::class, ['subject' => 'kept']);
            $this->fail('Expected a QueuePayloadException.');
        } catch (QueuePayloadException $e) {
            $this->assertStringContainsString('$count', $e->getMessage());
            $this->assertStringContainsString('$subject, $count', $e->getMessage());
            $this->assertStringContainsString('fromPayload()', $e->getMessage());
        }
    }

    public function testAnUnencodableCustomPayloadIsRefused(): void
    {
        $job = new class implements \Phue\Modules\Queue\JobInterface {
            public function handle(\Phue\Modules\Queue\JobContext $context): void
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function toPayload(): array
            {
                return ['handle' => fopen('php://memory', 'rb')];
            }

            /**
             * @param array<string, mixed> $payload
             */
            public static function fromPayload(array $payload): static
            {
                return new self();
            }
        };

        $this->expectException(QueuePayloadException::class);

        $this->codec->encode($job);
    }
}