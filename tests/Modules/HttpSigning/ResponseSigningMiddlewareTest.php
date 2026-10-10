<?php

declare(strict_types=1);

namespace Phue\Tests\Modules\HttpSigning;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Modules\HttpSigning\Middleware\ResponseSigningMiddleware;
use Phue\Modules\HttpSigning\SigningKeyManager;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class ResponseSigningMiddlewareTest extends TestCase
{
    private string $tmpDir;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_httpsigning_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);

        // Write http-signing config
        $secret = 'base64:' . base64_encode(random_bytes(32));
        $configContent = <<<'PHP'
<?php

declare(strict_types=1);

use function Phue\Core\env;

return [
    'enabled' => true,
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'test-key-1',
            'secret' => '$secret',
        ],
    ],
    'headers' => [
        'signature' => 'X-Response-Signature',
        'algorithm' => 'X-Response-Signature-Algo',
        'key_id' => 'X-Response-Signature-KeyId',
    ],
    'exclude_paths' => ['/health*', '/ping', '/metrics*'],
    'signed_headers' => ['Content-Type', 'Content-Length', 'ETag'],
    'include_public_key' => false,
];
PHP;
        file_put_contents($this->tmpDir . '/config/http-signing.php', str_replace('$secret', $secret, $configContent));

        // Write minimal app.php
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
<?php

return [
    'debug' => false,
    'modules' => [],
];
PHP
        );

        $this->container = new Container();
        $this->container->singleton(Config::class, fn(): Config => new Config($this->tmpDir . '/config'));
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    private function getConfig(): Config
    {
        return $this->container->get(Config::class);
    }

    private function getKeyManager(): SigningKeyManager
    {
        return SigningKeyManager::fromConfig($this->getConfig());
    }

    public function testSignsJsonResponse(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/api/users');
        $response = $middleware($request, fn() => Response::json(['name' => 'John']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeader('X-Response-Signature'));
        $this->assertSame('HS256', $response->getHeader('X-Response-Signature-Algo'));
        $this->assertSame('test-key-1', $response->getHeader('X-Response-Signature-KeyId'));
    }

    public function testSignsHtmlResponse(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/page');
        $response = $middleware($request, fn() => Response::html('<h1>Hello</h1>'));

        $this->assertNotEmpty($response->getHeader('X-Response-Signature'));
        $this->assertSame('HS256', $response->getHeader('X-Response-Signature-Algo'));
    }

    public function testExcludesConfiguredPaths(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: ['/health*', '/ping'],
            signedHeaders: []
        );

        // Health check - should not be signed
        $request = new Request('GET', '/health');
        $response = $middleware($request, fn() => Response::json(['status' => 'ok']));
        $this->assertNull($response->getHeader('X-Response-Signature'));

        // Health with subpath - should not be signed
        $request = new Request('GET', '/health/check');
        $response = $middleware($request, fn() => Response::json(['status' => 'ok']));
        $this->assertNull($response->getHeader('X-Response-Signature'));

        // Ping - exact match
        $request = new Request('GET', '/ping');
        $response = $middleware($request, fn() => Response::json(['pong' => true]));
        $this->assertNull($response->getHeader('X-Response-Signature'));

        // Non-excluded path - should be signed
        $request = new Request('GET', '/api/data');
        $response = $middleware($request, fn() => Response::json(['data' => 1]));
        $this->assertNotEmpty($response->getHeader('X-Response-Signature'));
    }

    public function testDoesNotSign5xxErrors(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: []
        );

        $request = new Request('GET', '/api/error');
        $response = $middleware($request, fn() => Response::json(['error' => 'Server error'], 500));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNull($response->getHeader('X-Response-Signature'));
    }

    public function testDoesNotSign4xxErrors(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: []
        );

        $request = new Request('GET', '/api/notfound');
        $response = $middleware($request, fn() => Response::json(['error' => 'Not found'], 404));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->getHeader('X-Response-Signature'));
    }

    public function testDoesNotSignStreamingResponses(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: []
        );

        // Response with Transfer-Encoding: chunked
        $request = new Request('GET', '/stream');
        $response = Response::json(['data' => 'stream']);
        $response->setHeader('Transfer-Encoding', 'chunked');

        $response = $middleware($request, fn() => $response);

        $this->assertNull($response->getHeader('X-Response-Signature'));
    }

    public function testIncludesConfiguredHeadersInSignature(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type', 'ETag']
        );

        $request = new Request('GET', '/api/data');
        $response = $middleware($request, function () {
            $resp = Response::json(['id' => 1]);
            $resp->setHeader('ETag', '"abc123"');
            return $resp;
        });

        $signature = $response->getHeader('X-Response-Signature');
        $this->assertNotEmpty($signature);

        // Verify the signature is valid
        $payload = $this->buildExpectedPayload($response, ['Content-Type', 'ETag']);
        $this->assertTrue($this->getKeyManager()->verify($payload, $signature, 'test-key-1'));
    }

    public function testSignatureVerificationWorks(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/api/verify');
        $response = $middleware($request, fn() => Response::json(['test' => 'verify']));

        $signature = $response->getHeader('X-Response-Signature');
        $payload = $this->buildExpectedPayload($response, ['Content-Type']);

        $this->assertTrue($this->getKeyManager()->verify($payload, $signature, 'test-key-1'));
    }

    public function testInvalidSignatureFailsVerification(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/api/verify');
        $response = $middleware($request, fn() => Response::json(['test' => 'verify']));

        $signature = $response->getHeader('X-Response-Signature');
        $payload = $this->buildExpectedPayload($response, ['Content-Type']);

        // Tamper with payload
        $tamperedPayload = $payload . 'tampered';
        $this->assertFalse($this->getKeyManager()->verify($tamperedPayload, $signature, 'test-key-1'));
    }

    public function testWrongKeyIdFailsVerification(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/api/verify');
        $response = $middleware($request, fn() => Response::json(['test' => 'verify']));

        $signature = $response->getHeader('X-Response-Signature');
        $payload = $this->buildExpectedPayload($response, ['Content-Type']);

        // Verify with non-existent key
        $this->assertFalse($this->getKeyManager()->verify($payload, $signature, 'non-existent-key'));
    }

    public function testDisabledSigningSkipsEntirely(): void
    {
        $disabledDir = sys_get_temp_dir() . '/phue_httpsigning_disabled_' . uniqid();
        mkdir($disabledDir . '/config', 0777, true);

        $secret = 'base64:' . base64_encode(random_bytes(32));
        file_put_contents($disabledDir . '/config/http-signing.php', <<<PHP
<?php

return [
    'enabled' => false,
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'test-key-1',
            'secret' => '$secret',
        ],
    ],
];
PHP
        );

        $disabledConfig = new Config($disabledDir . '/config');
        $disabledKeyManager = SigningKeyManager::fromConfig($disabledConfig);

        $middleware = new ResponseSigningMiddleware(
            keys: $disabledKeyManager,
            config: $disabledConfig,
            excludePaths: [],
            signedHeaders: []
        );

        $request = new Request('GET', '/api/data');
        $response = $middleware($request, fn() => Response::json(['data' => 1]));

        $this->assertNull($response->getHeader('X-Response-Signature'));

        $this->rmTree($disabledDir);
    }

    public function testRespectsCustomHeaderNames(): void
    {
        $customDir = sys_get_temp_dir() . '/phue_httpsigning_custom_' . uniqid();
        mkdir($customDir . '/config', 0777, true);

        $secret = 'base64:' . base64_encode(random_bytes(32));
        file_put_contents($customDir . '/config/http-signing.php', <<<PHP
<?php

return [
    'enabled' => true,
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'custom-key',
            'secret' => '$secret',
        ],
    ],
    'headers' => [
        'signature' => 'X-Custom-Signature',
        'algorithm' => 'X-Custom-Algo',
        'key_id' => 'X-Custom-KeyId',
    ],
];
PHP
        );

        $customConfig = new Config($customDir . '/config');
        $customKeyManager = SigningKeyManager::fromConfig($customConfig);

        $middleware = new ResponseSigningMiddleware(
            keys: $customKeyManager,
            config: $customConfig,
            excludePaths: [],
            signedHeaders: []
        );

        $request = new Request('GET', '/api/data');
        $response = $middleware($request, fn() => Response::json(['data' => 1]));

        $this->assertNotEmpty($response->getHeader('X-Custom-Signature'));
        $this->assertSame('HS256', $response->getHeader('X-Custom-Algo'));
        $this->assertSame('custom-key', $response->getHeader('X-Custom-KeyId'));

        $this->rmTree($customDir);
    }

    public function testEmptyBodyStillSigned(): void
    {
        $middleware = new ResponseSigningMiddleware(
            keys: $this->getKeyManager(),
            config: $this->getConfig(),
            excludePaths: [],
            signedHeaders: ['Content-Type']
        );

        $request = new Request('GET', '/empty');
        $response = $middleware($request, fn() => new Response('', 204));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeader('X-Response-Signature'));
    }

    public function testKeyRotationMultipleKeys(): void
    {
        $rotationDir = sys_get_temp_dir() . '/phue_httpsigning_rotation_' . uniqid();
        mkdir($rotationDir . '/config', 0777, true);

        $secret1 = 'base64:' . base64_encode(random_bytes(32));
        $secret2 = 'base64:' . base64_encode(random_bytes(32));
        file_put_contents($rotationDir . '/config/http-signing.php', <<<PHP
<?php

return [
    'enabled' => true,
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'key-new',
            'secret' => '$secret1',
        ],
        'previous' => [
            'id' => 'key-old',
            'secret' => '$secret2',
        ],
    ],
];
PHP
        );

        $rotationConfig = new Config($rotationDir . '/config');
        $rotationKeyManager = SigningKeyManager::fromConfig($rotationConfig);

        // Current key should be 'key-new' (first in config)
        $currentKey = $rotationKeyManager->getCurrentKey();
        $this->assertSame('key-new', $currentKey['id']);

        // Both keys should be available
        $allKeys = $rotationKeyManager->getAllKeys();
        $this->assertArrayHasKey('key-new', $allKeys);
        $this->assertArrayHasKey('key-old', $allKeys);

        $this->rmTree($rotationDir);
    }

    public function testSigningKeyManagerFromConfigValidatesAlgorithm(): void
    {
        $badDir = sys_get_temp_dir() . '/phue_httpsigning_bad_' . uniqid();
        mkdir($badDir . '/config', 0777, true);

        file_put_contents($badDir . '/config/http-signing.php', <<<'PHP'
<?php

return [
    'algorithm' => 'invalid-algo',
    'keys' => [
        'current' => [
            'id' => 'key-1',
            'secret' => 'base64:' . base64_encode(random_bytes(32)),
        ],
    ],
];
PHP
        );

        $badConfig = new Config($badDir . '/config');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported signing algorithm');
        SigningKeyManager::fromConfig($badConfig);

        $this->rmTree($badDir);
    }

    public function testSigningKeyManagerValidatesKeyLength(): void
    {
        $badDir = sys_get_temp_dir() . '/phue_httpsigning_badlen_' . uniqid();
        mkdir($badDir . '/config', 0777, true);

        file_put_contents($badDir . '/config/http-signing.php', <<<'PHP'
<?php

return [
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'key-1',
            'secret' => 'base64:' . base64_encode(random_bytes(16)), // Too short
        ],
    ],
];
PHP
        );

        $badConfig = new Config($badDir . '/config');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too short');
        SigningKeyManager::fromConfig($badConfig);

        $this->rmTree($badDir);
    }

    public function testSigningKeyManagerValidatesBase64Secret(): void
    {
        $badDir = sys_get_temp_dir() . '/phue_httpsigning_badb64_' . uniqid();
        mkdir($badDir . '/config', 0777, true);

        file_put_contents($badDir . '/config/http-signing.php', <<<'PHP'
<?php

return [
    'algorithm' => 'hmac-sha256',
    'keys' => [
        'current' => [
            'id' => 'key-1',
            'secret' => 'base64:not-valid-base64!!!',
        ],
    ],
];
PHP
        );

        $badConfig = new Config($badDir . '/config');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid base64');
        SigningKeyManager::fromConfig($badConfig);

        $this->rmTree($badDir);
    }

    public function testSigningKeyManagerRequiresKeys(): void
    {
        $badDir = sys_get_temp_dir() . '/phue_httpsigning_nokeys_' . uniqid();
        mkdir($badDir . '/config', 0777, true);

        file_put_contents($badDir . '/config/http-signing.php', <<<'PHP'
<?php

return [
    'algorithm' => 'hmac-sha256',
    'keys' => [],
];
PHP
        );

        $badConfig = new Config($badDir . '/config');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No signing keys configured');
        SigningKeyManager::fromConfig($badConfig);

        $this->rmTree($badDir);
    }

    public function testAlgorithmIdentifier(): void
    {
        $hmacManager = $this->getKeyManager();
        $this->assertSame('HS256', $hmacManager->getAlgorithmIdentifier());

        // Test RSA if OpenSSL available
        if (extension_loaded('openssl')) {
            $rsaDir = sys_get_temp_dir() . '/phue_httpsigning_rsa_' . uniqid();
            mkdir($rsaDir . '/config', 0777, true);

            // Generate RSA key pair
            $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($keyPair !== false) {
                openssl_pkey_export($keyPair, $privateKeyPem);
                $details = openssl_pkey_get_details($keyPair);
                $publicKeyPem = $details['key'] ?? '';

                file_put_contents($rsaDir . '/config/http-signing.php', <<<PHP
<?php

return [
    'algorithm' => 'rsa-pss-sha256',
    'keys' => [
        'current' => [
            'id' => 'rsa-key',
            'secret' => <<<'PEM'
$privateKeyPem
PEM
            ,
            'public_key' => <<<'PEM'
$publicKeyPem
PEM
        ,
        ],
    ],
];
PHP
                );

                try {
                    $rsaConfig = new Config($rsaDir . '/config');
                    $rsaManager = SigningKeyManager::fromConfig($rsaConfig);
                    $this->assertSame('RS256', $rsaManager->getAlgorithmIdentifier());
                } catch (\RuntimeException $e) {
                    $this->markTestSkipped('RSA key generation not available: ' . $e->getMessage());
                }

                $this->rmTree($rsaDir);
            }
        }
    }

    private function buildExpectedPayload(Response $response, array $signedHeaders): string
    {
        $headers = $response->getHeaders();
        $headerLines = [];
        $signedHeaderNames = array_map('strtolower', $signedHeaders);

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $signedHeaderNames, true)) {
                $headerLines[] = strtolower($name) . ':' . $value;
            }
        }

        sort($headerLines);
        $canonicalHeaders = implode("\n", $headerLines);
        $body = $response->getContent();

        return $canonicalHeaders . "\n.\n" . $body;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rmTree($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}