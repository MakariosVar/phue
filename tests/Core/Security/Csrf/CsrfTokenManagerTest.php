<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security\Csrf;

use Phue\Core\Security\Csrf\CsrfTokenManager;
use Phue\Core\Security\Csrf\Exceptions\MissingApplicationKeyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF signing key and token format.
 *
 * Two properties are being defended here, and they are easy to confuse:
 *
 *  1. Confidentiality of nothing, integrity of the token. A synchronizer token only works
 *     if the server can tell "the one I issued" from "one the attacker chose". Randomness
 *     alone is not enough for a double-submit cookie: whoever can write the cookie (a
 *     subdomain, a sibling app on the same registrable domain) can also echo a matching
 *     header. The MAC is what closes that.
 *
 *  2. verify() must be total. Every malformed input returns false rather than throwing,
 *     because an exception is itself an oracle and a cheap way to probe the server.
 *
 * @package Phue\Tests\Core\Security\Csrf
 */
#[Group('unit')]
final class CsrfTokenManagerTest extends TestCase
{
    private static function validKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function testFromConfigValueAcceptsAWellFormedKey(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $this->assertInstanceOf(CsrfTokenManager::class, $manager);
    }

    /**
     * The message has to survive into an operator's terminal. A bare
     * "missing application key" leaves the fix undiscoverable.
     */
    public function testMissingKeyThrowsWithAnActionableMessage(): void
    {
        try {
            CsrfTokenManager::fromConfigValue(null);
            $this->fail('Expected a MissingApplicationKeyException.');
        } catch (MissingApplicationKeyException $e) {
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
            $this->assertStringContainsString('generate:key', $e->getMessage());
        }
    }

    #[DataProvider('unusableKeys')]
    public function testUnusableKeyValuesAreRejectedWithTheSameActionableMessage(?string $configured): void
    {
        $this->expectException(MissingApplicationKeyException::class);
        $this->expectExceptionMessageMatches('/generate:key/');

        CsrfTokenManager::fromConfigValue($configured);
    }

    /**
     * "empty", "null", "false" and "true" are what Env::load() coerces an empty or
     * literal .env entry into, so they reach this code as real strings rather than null.
     *
     * @return array<string, array{string|null}>
     */
    public static function unusableKeys(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            'env empty literal' => ['empty'],
            'env null literal' => ['null'],
            'env false literal' => ['false'],
            'missing prefix' => [base64_encode(random_bytes(32))],
            'not base64' => ['base64:!!!not-base64!!!'],
            'too short' => ['base64:' . base64_encode(random_bytes(16))],
            'almost too short' => ['base64:' . base64_encode(random_bytes(31))],
        ];
    }

    public function testGenerateProducesTwoBase64urlSegments(): void
    {
        $token = CsrfTokenManager::fromConfigValue(self::validKey())->generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        $this->assertCount(2, explode('.', $token));
    }

    /**
     * base64 standard alphabet contains '+', '/' and '='. All three are either illegal in
     * a header value, illegal unencoded in a form field, or silently mangled in transit.
     */
    public function testTokenUsesUrlSafeAlphabetSoItSurvivesHeadersAndForms(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        for ($i = 0; $i < 50; $i++) {
            $token = $manager->generate();

            $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token);
        }
    }

    public function testFreshTokenPerCallSoTwoBrowsersNeverShareOne(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $tokens = [];

        for ($i = 0; $i < 100; $i++) {
            $tokens[] = $manager->generate();
        }

        $this->assertCount(100, array_unique($tokens));
    }

    public function testVerifyAcceptsATokenItJustIssued(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $this->assertTrue($manager->verify($manager->generate()));
    }

    public function testVerifyRejectsATokenSignedWithADifferentKey(): void
    {
        $issuer = CsrfTokenManager::fromConfigValue(self::validKey());
        $verifier = CsrfTokenManager::fromConfigValue(self::validKey());

        $this->assertFalse($verifier->verify($issuer->generate()));
    }

    public function testVerifyRejectsATamperedNonce(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());
        [$nonce, $mac] = explode('.', $manager->generate());

        $this->assertFalse($manager->verify('AAAA.' . $mac));
        $this->assertNotSame($nonce, 'AAAA');
    }

    public function testVerifyRejectsATamperedMac(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());
        [$nonce, $mac] = explode('.', $manager->generate());

        $this->assertFalse($manager->verify($nonce . '.AAAA'));
        $this->assertNotSame($mac, 'AAAA');
    }

    /**
     * A single bit flipped anywhere in the token must invalidate it.
     */
    public function testVerifyRejectsEverySingleCharacterMutation(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());
        $token = $manager->generate();

        for ($i = 0, $length = strlen($token); $i < $length; $i++) {
            $mutated = $token;
            $mutated[$i] = $mutated[$i] === 'A' ? 'B' : 'A';

            $this->assertFalse(
                $manager->verify($mutated),
                "Mutation at offset {$i} was accepted: {$mutated}"
            );
        }
    }

    /**
     * An attacker-supplied cookie value must not be accepted merely for looking
     * well-formed. This is the cookie-injection case the MAC exists for.
     */
    public function testWellFormedButUnsignedTokenIsRejected(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $attackerNonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $attackerMac = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->assertFalse($manager->verify($attackerNonce . '.' . $attackerMac));
    }

    /**
     * verify() is on the hot path of every unsafe request and its input is fully
     * attacker-controlled. Throwing on a malformed value would turn a bad header into a
     * 500 and, worse, give an attacker a way to distinguish shapes.
     */
    #[DataProvider('malformedTokens')]
    public function testMalformedTokensReturnFalseInsteadOfThrowing(string $presented): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $this->assertFalse($manager->verify($presented));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'single dot' => ['.'],
            'no separator' => ['abcdef'],
            'empty nonce' => ['.abcdef'],
            'empty mac' => ['abcdef.'],
            'three segments' => ['a.b.c'],
            'four segments' => ['a.b.c.d'],
            'illegal characters' => ["abc\r\n.def"],
            'spaces' => ['abc def.ghi'],
            'standard base64 alphabet' => ['a+b/c=.d+e/f='],
            'unicode' => ["\u{00e9}\u{00e8}.abc"],
            'very long' => [str_repeat('A', 8192) . '.B'],
            'very long mac' => ['AAAA.' . str_repeat('B', 8192)],
            'nul byte' => ["ab\0cd.ef"],
            'trailing newline' => ["abcdef.ghi\n"],
            'leading space' => [" abcdef.ghi"],
        ];
    }

    public function testVerifyTreatsAnOverlongNonceAsInvalidRatherThanDoingTheWork(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());

        $this->assertFalse($manager->verify(str_repeat('A', 100000) . '.' . str_repeat('B', 44)));
    }

    /**
     * Every failure mode must be indistinguishable from the outside. If "malformed" and
     * "wrong signature" produced different observable behaviour, the difference would be
     * something to measure.
     */
    public function testAllRejectionPathsReturnTheSameFalse(): void
    {
        $manager = CsrfTokenManager::fromConfigValue(self::validKey());
        [$nonce, $mac] = explode('.', $manager->generate());

        $rejections = [
            $manager->verify(''),
            $manager->verify('nonsense'),
            $manager->verify('AAAA.' . $mac),
            $manager->verify($nonce . '.AAAA'),
            $manager->verify(str_repeat('A', 8192) . '.B'),
        ];

        $this->assertSame([false], array_values(array_unique($rejections, SORT_REGULAR)));
    }
}