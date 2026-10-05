<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Request;
use Phue\Core\Routing\Router;
use Phue\Core\Websocket\Connection;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Websocket\WebsocketModule;
use Phue\Tests\Websocket\Stubs\SessionUser;
use Phue\Tests\Websocket\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * A browser tab holds a session cookie and nothing else. Without a session path the module can
 * only read a bearer token, so every browser handshake is refused with 1008 and the realtime
 * channel is unreachable from a page — which is the only place it is wanted from.
 *
 * Refusal is asserted by the transport: onConnect closes the socket when it cannot identify the
 * caller, so an empty list of closes is the pass condition and a populated one is the failure.
 */
final class WebsocketSessionIdentityTest extends TestCase
{
    private Container $container;

    private FakeTransport $transport;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A real session, because the login store starts one if none is active and starting it
        // replaces $_SESSION wholesale — so writing the user id into the superglobal before that
        // happens is silently discarded, and the test would pass for the wrong reason.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    public function testASessionIsAcceptedWithoutAToken(): void
    {
        $this->module(withAuth: true);

        $_SESSION['__user_id'] = 1;

        // The precondition, stated: without an active session the store starts one, which
        // replaces $_SESSION and drops the user id on the floor. A test that skipped this check
        // would report "refused" and look like an authentication bug.
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'the fixture session must be live');
        $this->assertSame(1, (new SessionLoginStore())->getId(), 'the store must read the fixture');
        $this->assertInstanceOf(
            SessionUser::class,
            $this->container->get(\Phue\Modules\Auth\AuthManager::class)->authenticate(new Request('GET', '/ws')),
            'the auth manager must resolve the fixture user'
        );

        $this->handshake(new Connection('7'));

        $this->assertSame([], $this->refusals(), 'a logged-in session must not be refused');
    }

    public function testAMissingSessionIsStillRefused(): void
    {
        $this->module(withAuth: true);

        $this->handshake(new Connection('8'));

        $this->assertSame(
            [['8', 1008, 'authentication required']],
            $this->refusals(),
            'an anonymous handshake must be refused, or the channel is an open door'
        );
    }

    public function testASessionIsPreferredOverAToken(): void
    {
        $this->module(withAuth: true);

        $_SESSION['__user_id'] = 1;
        $this->handshake(
            new Connection('9'),
            headers: ['Authorization' => 'Bearer somebody-elses-token']
        );

        // A logged-in browser should not be pushed towards a token because one also happens to
        // be present: the session is the stronger statement of who this is.
        $this->assertSame([], $this->refusals());
    }

    public function testATokenStillWorksWithoutTheAuthModule(): void
    {
        $this->module(withAuth: false);

        $this->handshake(new Connection('10'), query: ['token' => 'abc']);

        $this->assertSame([], $this->refusals());
    }

    public function testAnAnonymousHandshakeIsRefusedWithoutTheAuthModuleToo(): void
    {
        $this->module(withAuth: false);

        $this->handshake(new Connection('11'));

        $this->assertSame([['11', 1008, 'authentication required']], $this->refusals());
    }

    public function testAnAuthFailureIsARefusalRatherThanAnError(): void
    {
        $this->module(withAuth: true, authThrows: true);

        // The handshake must not turn a logged-out visitor into a server error.
        $this->handshake(new Connection('12'));

        $this->assertSame([['12', 1008, 'authentication required']], $this->refusals());
    }

    /**
     * The transport records a Connection object; the identifier is what an assertion can read.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function refusals(): array
    {
        return array_map(
            static fn (array $entry): array => [(string) $entry[0]->id(), $entry[1], $entry[2]],
            $this->transport->closed
        );
    }

    /**
     * Connect, then say something.
     *
     * A refusal cannot be delivered during the handshake — the socket is not registered yet — so
     * it is sent when the client's first frame arrives. Every real client sends one; a test that
     * only connects would therefore see a refusal that never lands and pass for the wrong reason.
     * The channel is empty on purpose, so it subscribes to nothing and sends nothing.
     */
    private function handshake(Connection $connection, array $headers = [], array $query = [], string $path = '/ws'): Connection
    {
        $this->transport->connect($connection, $headers, $query, $path);
        $this->transport->message($connection, '{"act":"sub","ch":""}');

        return $connection;
    }

    private function module(bool $withAuth, bool $authThrows = false): void
    {
        $dir = sys_get_temp_dir() . '/phue_ws_session_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        file_put_contents($dir . '/app.php', '<?php return ["debug" => true];');
        file_put_contents(
            $dir . '/websocket.php',
            '<?php return ' . var_export([
                'enabled' => true,
                'debug' => true,
                'allowed_origins' => ['*'],
                // The transport is chosen by name so the module wires itself over this one
                // rather than over whatever happens to be bound.
                'transport' => FakeTransport::class,
            ], true) . ';'
        );

        $this->container = new Container();
        $this->container->singleton(Config::class, static fn (): Config => new Config($dir));

        $this->transport = new FakeTransport();
        $this->container->singleton(FakeTransport::class, fn (): FakeTransport => $this->transport);

        if ($withAuth) {
            $loginStore = new SessionLoginStore();

            $this->container->singleton(
                AuthManager::class,
                $authThrows
                    // A store that cannot answer is a refusal, not a crash.
                    ? static fn (): AuthManager => new AuthManager([
                        new class ($loginStore) implements \Phue\Modules\Auth\Authenticator\AuthenticatorInterface {
                            public function __construct(private readonly SessionLoginStore $store)
                            {
                            }

                            public function authenticate(Request $request): ?\Phue\Modules\Auth\IdentityInterface
                            {
                                throw new \RuntimeException('session storage is unavailable');
                            }
                        },
                    ])
                    : static fn (): AuthManager => new AuthManager([
                        new SessionAuthenticator(SessionUser::class, $loginStore),
                    ])
            );
        }

        $module = new WebsocketModule();
        $module->setDependencies(new Router(), $this->container, new EventDispatcher());
        $module->init();
    }
}
