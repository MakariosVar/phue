<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Security;

use DateTimeImmutable;
use InvalidArgumentException;
use Phue\Core\Security\Cookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CookieTest extends TestCase
{
    /**
     * A cookie is only useful if the browser can be told to keep or drop it. A session
     * cookie with no expiry attribute gets dropped, so the shape of the attributes
     * is part of the contract, not decoration.
     */
    public function testMinimalCookieRendersNameValueAndPath(): void
    {
        $cookie = new Cookie('sid', 'abc123');

        $this->assertSame('sid=abc123; Path=/', $cookie->toHeaderString());
    }

    public function testSameSiteIsCanonicalisedRegardlessOfInputCasing(): void
    {
        foreach (['lax' => 'Lax', 'LAX' => 'Lax', 'strict' => 'Strict', 'none' => 'None'] as $input => $expected) {
            $cookie = new Cookie('sid', 'v', sameSite: $input, secure: true);

            $this->assertSame($expected, $cookie->getSameSite());
            $this->assertStringContainsString('SameSite=' . $expected, $cookie->toHeaderString());
        }
    }

    /**
     * Browsers reject SameSite=None without Secure. Emitting it anyway produces a
     * cross-site cookie the client silently discards, which looks like a working
     * feature and is not. Refuse at construction instead.
     */
    public function testSameSiteNoneWithoutSecureIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('sid', 'v', sameSite: 'None');
    }

    public function testUnknownSameSiteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('sid', 'v', sameSite: 'Whatever');
    }

    /**
     * The cookie value reaches a response header line verbatim. A CR or LF in it is
     * response splitting, so the grammar check is the security control, not validation.
     */
    #[DataProvider('illegalValues')]
    public function testValueCarryingHeaderBreakingCharactersIsRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('sid', $value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function illegalValues(): array
    {
        return [
            'CRLF' => ["abc\r\nSet-Cookie: pwned=1"],
            'bare LF' => ["abc\ndef"],
            'bare CR' => ["abc\rdef"],
            'semicolon' => ['abc; Path=/'],
            'comma' => ['abc,def'],
            'double quote' => ['abc"def'],
            'backslash' => ['abc\\def'],
            'space' => ['abc def'],
            'NUL' => ["abc\0def"],
            'DEL' => ["abc\x7Fdef"],
        ];
    }

    #[DataProvider('legalValues')]
    public function testValuesInsideTheCookieOctetGrammarAreAccepted(string $value): void
    {
        $cookie = new Cookie('sid', $value);

        $this->assertSame($value, $cookie->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legalValues(): array
    {
        return [
            'base64url token' => ['Zm9vYmFyLWJhcl9iYXo_1234-ABC'],
            'empty' => [''],
            'punycode-ish' => ['xn--nxasmq6b'],
            'brackets and braces' => ['a[b]c{d}e'],
        ];
    }

    #[DataProvider('illegalNames')]
    public function testNameCarryingIllegalCharactersIsRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie($name, 'v');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function illegalNames(): array
    {
        return [
            'separator' => ['a=b'],
            'space' => ['a b'],
            'CRLF' => ["a\r\nSet-Cookie: b=1"],
            'empty' => [''],
        ];
    }

    public function testAttributeOrderIsFixedSoSnapshotsAreStable(): void
    {
        $cookie = new Cookie(
            name: 'sid',
            value: 'v',
            path: '/api',
            domain: 'example.com',
            maxAge: 3600,
            expires: new DateTimeImmutable('@1000000000'),
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
        );

        $this->assertSame(
            'sid=v; Max-Age=3600; Domain=example.com; Path=/api; '
                . 'Expires=Sun, 09 Sep 2001 01:46:40 GMT; Secure; HttpOnly; SameSite=Lax',
            $cookie->toHeaderString(),
        );
    }

    public function testFlagAttributesAreEmittedWithoutAValue(): void
    {
        $cookie = new Cookie('sid', 'v', secure: true, httpOnly: true);

        $this->assertStringContainsString('; Secure', $cookie->toHeaderString());
        $this->assertStringContainsString('; HttpOnly', $cookie->toHeaderString());
        $this->assertStringNotContainsString('Secure=', $cookie->toHeaderString());
        $this->assertStringNotContainsString('HttpOnly=', $cookie->toHeaderString());
    }

    public function testRemovalCookieExpiresInThePastAndIsRecognisedAsExpired(): void
    {
        $cookie = Cookie::removal('sid', '/', 'example.com');

        $this->assertTrue($cookie->isExpired());
        $this->assertStringContainsString('Max-Age=0', $cookie->toHeaderString());
        $this->assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $cookie->toHeaderString());
    }

    public function testMaxAgeZeroIsExpiredEvenWithoutAnExpiresAttribute(): void
    {
        $this->assertTrue((new Cookie('sid', '', maxAge: 0))->isExpired());
    }

    public function testPositiveMaxAgeIsNotExpired(): void
    {
        $this->assertFalse((new Cookie('sid', 'v', maxAge: 60))->isExpired());
        $this->assertFalse((new Cookie('sid', 'v', expires: new DateTimeImmutable('+1 hour')))->isExpired());
    }

    public function testSessionCookieCarriesNoExpiryAttribute(): void
    {
        $cookie = Cookie::create('sid', 'v', secure: true, httpOnly: true, sameSite: 'Lax');

        $this->assertFalse($cookie->isExpired());
        $this->assertSame('sid=v; Path=/; Secure; HttpOnly; SameSite=Lax', $cookie->toHeaderString());
    }

    public function testPathMustBeAbsoluteOrEmpty(): void
    {
        $this->assertSame('sid=v', (new Cookie('sid', 'v', path: ''))->toHeaderString());

        $this->expectException(InvalidArgumentException::class);
        new Cookie('sid', 'v', path: 'api');
    }

    public function testIllegalDomainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cookie('sid', 'v', domain: "example.com\r\nSet-Cookie: x=1");
    }
}