<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Http\Exceptions\HttpException;
use Phue\Core\Security\Csrf\Exceptions\CsrfTokenMismatchException;
use Phue\Modules\Auth\Persistence\SessionLoginStore;

/**
 * Session hardening around login and logout.
 *
 * These are the two moments the CSRF token cannot cover, because they are what the token
 * is protecting in the first place. Session fixation in particular survives every request
 * level control: the attacker's planted session id stays valid across the login the victim
 * performs, so nothing about verifying a token would notice.
 *
 * @package Phue\Tests\Auth
 */
#[Group('unit')]
final class SessionHardeningTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        parent::tearDown();
    }

    /**
     * Marking a session cookie Secure based on X-Forwarded-Proto would let a client choose
     * the cookie's transport attribute: forge "https" and the cookie is marked Secure, which
     * does not upgrade anything and leaves it sendable over plaintext. Asserted on behaviour
     * rather than on the source text, so a reworded comment cannot pass or fail this.
     */
    public function testSecureFlagIgnoresAClientControlledForwardedProtoHeader(): void
    {
        $isHttps = new \ReflectionMethod(SessionLoginStore::class, 'isHttps');

        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertFalse(
            $isHttps->invoke(null),
            'X-Forwarded-Proto is client-controlled and must not decide the Secure flag.'
        );

        $_SERVER = ['HTTPS' => 'on'];
        $this->assertTrue($isHttps->invoke(null));

        $_SERVER = ['HTTPS' => 'off'];
        $this->assertFalse($isHttps->invoke(null));

        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTPS' => 'on'];
        $this->assertTrue($isHttps->invoke(null), 'Real HTTPS still counts when the header lies the other way.');
    }

    /**
     * The regeneration has to happen on login. If it only happened on logout, a planted
     * session id would still be valid immediately after the victim authenticates, and no
     * request-level control would notice because nothing about the token was wrong.
     */
    public function testLoginRotatesTheSessionIdAndWritesTheIdentityAfterwards(): void
    {
        $store = new SessionLoginStore();

        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Auth/Persistence/SessionLoginStore.php'
        );

        $body = $this->methodBody($source, 'store');
        $regenerate = strpos($body, 'session_regenerate_id');
        $write = strpos($body, '$_SESSION');

        $this->assertIsInt($regenerate, 'store() must rotate the session id on login.');
        $this->assertLessThan(
            $write,
            $regenerate,
            'Rotating after the identity is written would leave the old id able to inherit it.'
        );

        // Behavioural check that the store is usable and holds the id across the rotation.
        $store->store(42);
        $this->assertSame(42, $store->getId());
    }

    public function testCsrfRejectionIsA403NotA500(): void
    {
        $exception = CsrfTokenMismatchException::because('token failed signature verification');

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame(403, $exception->getStatusCode());
    }

    /**
     * Body of a method, from its docblock to the next method.
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');

        if ($start === false) {
            $this->fail('Method ' . $method . ' not found.');
        }

        $next = strpos($source, "\n    public function ", $start);
        $nextPrivate = strpos($source, "\n    private function ", $start);

        if ($nextPrivate !== false && ($next === false || $nextPrivate < $next)) {
            $next = $nextPrivate;
        }

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}