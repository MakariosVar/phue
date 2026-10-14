# HTTP Response Signing

The **HttpSigning** module adds cryptographic signatures to HTTP responses, providing **integrity** and **authenticity** guarantees. Clients can verify that responses haven't been tampered with in transit and that they genuinely originated from your server.

## Installation

The module is enabled by default in `config/app.php`:

```php
'modules' => [
    // ...
    \Phue\Modules\HttpSigning\HttpSigningModule::class,
],
```

Configuration lives in `config/http-signing.php`.

## How It Works

The middleware runs as the **outermost layer** in the middleware pipeline (last registered = first executed). This means it signs the complete response including headers added by other middleware (SecurityMiddleware, CSRF, RateLimit, etc.).

### Signature Format

Three headers are added to every signed response:

| Header | Description |
|--------|-------------|
| `X-Response-Signature` | Base64url-encoded signature of the canonical payload |
| `X-Response-Signature-Algo` | Algorithm identifier: `HS256` (HMAC-SHA256) or `RS256` (RSA-PSS-SHA256) |
| `X-Response-Signature-KeyId` | Key identifier for rotation support |

### Canonical Payload

The signed payload is constructed as:

```
canonical_headers
.
body
```

Where `canonical_headers` = sorted header lines `name: value` (lowercase names) for headers listed in `signed_headers` config.

Example:
```
content-type:application/json
content-length:27
etag:"abc123"
.
{"user":{"id":1,"name":"John"}}
```

## Configuration

### `config/http-signing.php`

```php
return [
    /**
     * Enable/disable response signing globally.
     */
    'enabled' => env('HTTP_SIGNING_ENABLED', true),

    /**
     * Signing algorithm: 'hmac-sha256' | 'rsa-pss-sha256'
     * - hmac-sha256: symmetric, fast, shared secret (default, no external deps)
     * - rsa-pss-sha256: asymmetric, requires OpenSSL extension
     */
    'algorithm' => env('HTTP_SIGNING_ALGO', 'hmac-sha256'),

    /**
     * Key configuration.
     * For HMAC: base64-encoded secret (32+ bytes recommended)
     * For RSA: array with 'private_key' (PEM) and optional 'public_key' (PEM)
     */
    'keys' => [
        'current' => [
            'id' => 'key-1',
            'secret' => env('HTTP_SIGNING_SECRET', 'base64:' . base64_encode(random_bytes(32))),
        ],
        // 'previous' => [
        //     'id' => 'key-0',
        //     'secret' => env('HTTP_SIGNING_SECRET_OLD', ''),
        // ],
    ],

    /**
     * Header names for the signature and metadata.
     */
    'headers' => [
        'signature' => 'X-Response-Signature',
        'algorithm' => 'X-Response-Signature-Algo',
        'key_id'    => 'X-Response-Signature-KeyId',
    ],

    /**
     * Paths to exclude from signing (exact match or prefix with *).
     * Health checks, static assets, etc.
     */
    'exclude_paths' => [
        '/health*',
        '/ping',
        '/metrics*',
        '/_debugbar*',
    ],

    /**
     * Which response headers to include in the signed payload.
     * Empty = sign only body. '*' = all headers.
     */
    'signed_headers' => [
        'Content-Type',
        'Content-Length',
        'ETag',
        'Last-Modified',
        'Cache-Control',
    ],

    /**
     * For asymmetric (RSA): whether to include public key in response for verification.
     * Not recommended for production; use JWKS endpoint instead.
     */
    'include_public_key' => false,
];
```

## Key Management

### HMAC-SHA256 (Symmetric, Default)

Generate a secret:
```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

Set in `.env`:
```env
HTTP_SIGNING_SECRET=base64:your-generated-secret
```

### RSA-PSS-SHA256 (Asymmetric)

Generate key pair:
```bash
# Private key
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out private_key.pem

# Public key
openssl pkey -in private_key.pem -pubout -out public_key.pem
```

Configure:
```php
'keys' => [
    'current' => [
        'id' => 'rsa-key-1',
        'secret' => file_get_contents('/path/to/private_key.pem'),
        'public_key' => file_get_contents('/path/to/public_key.pem'),
    ],
],
```

### Key Rotation

Multiple keys are supported. The **first key in the array** is used for signing; all keys are available for verification.

```php
'keys' => [
    'current' => [
        'id' => 'key-new',
        'secret' => 'base64:...',
    ],
    'previous' => [
        'id' => 'key-old',
        'secret' => 'base64:...',
    ],
],
```

Clients should verify against the `KeyId` in the response header.

## Client Verification

### PHP Example (HMAC)

```php
function verifyResponse(string $payload, string $signature, string $secret): bool
{
    $expected = hash_hmac('sha256', $payload, $secret, true);
    $decodedSig = base64UrlDecode($signature);
    return hash_equals($expected, $decodedSig);
}

function base64UrlDecode(string $data): string|false
{
    $padding = strlen($data) % 4;
    if ($padding) {
        $data .= str_repeat('=', 4 - $padding);
    }
    $data = strtr($data, '-_', '+/');
    return base64_decode($data, true);
}

// Build canonical payload from response
$headers = $response->getHeaders();
$signedHeaders = ['Content-Type', 'Content-Length', 'ETag'];
$headerLines = [];

foreach ($headers as $name => $value) {
    if (in_array(strtolower($name), array_map('strtolower', $signedHeaders), true)) {
        $headerLines[] = strtolower($name) . ':' . $value;
    }
}
sort($headerLines);

$payload = implode("\n", $headerLines) . "\n.\n" . $response->getContent();
$signature = $response->getHeader('X-Response-Signature');

if (verifyResponse($payload, $signature, $sharedSecret)) {
    echo "Signature valid!";
}
```

### JavaScript Example (HMAC)

```javascript
async function verifyResponse(response, secret) {
    // Get signature headers
    const signature = response.headers.get('X-Response-Signature');
    const keyId = response.headers.get('X-Response-Signature-KeyId');
    
    // Build canonical payload
    const signedHeaders = ['content-type', 'content-length', 'etag'];
    const headerLines = [];
    
    for (const [name, value] of response.headers.entries()) {
        if (signedHeaders.includes(name.toLowerCase())) {
            headerLines.push(`${name.toLowerCase()}:${value}`);
        }
    }
    headerLines.sort();
    
    const body = await response.text();
    const payload = headerLines.join('\n') + '\n.\n' + body;
    
    // Verify HMAC
    const key = await crypto.subtle.importKey(
        'raw',
        base64UrlDecode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['verify']
    );
    
    const sigBytes = base64UrlDecode(signature);
    return await crypto.subtle.verify('HMAC', key, sigBytes, new TextEncoder().encode(payload));
}

function base64UrlDecode(str) {
    const padding = str.length % 4;
    if (padding) str += '='.repeat(4 - padding);
    return Uint8Array.from(atob(str.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
}
```

## Excluded Responses

The middleware **skips signing** for:
- Paths matching `exclude_paths` patterns (health checks, metrics, etc.)
- 4xx and 5xx error responses
- Streaming responses (`Transfer-Encoding: chunked`)
- When globally disabled via `enabled: false`

## Security Considerations

1. **Key secrecy**: HMAC secrets must be kept confidential. Rotate periodically.
2. **HTTPS required**: Signatures don't encrypt; always use TLS in production.
3. **Replay protection**: Signatures don't prevent replay attacks. Add timestamps/nonces if needed.
4. **Header selection**: Only sign headers that are deterministic and meaningful for integrity.
5. **Key rotation**: Plan for key rotation. Keep old keys for verification during transition.

## Performance

- **HMAC-SHA256**: ~0.1-0.3ms per response (negligible)
- **RSA-PSS-SHA256**: ~1-3ms per response (use only when asymmetric verification is required)
- No session storage, no database writes, no locks — works identically under FPM, Swoole and RoadRunner

## Testing

Run the test suite (no Redis, no OpenSSL required — RSA paths skip when the
extension is absent):

```bash
docker exec phue_app php bin/phue_test --impact tests/Modules/HttpSigning
```

## Architecture

```
HttpSigningModule
├── SigningKeyManager (singleton)
│   ├── HMAC-SHA256 (hash_hmac)
│   └── RSA-PSS-SHA256 (openssl_sign/verify)
└── ResponseSigningMiddleware (global, outermost)
    ├── Path exclusion check
    ├── Status code check (skip >= 400)
    ├── Streaming check
    ├── Canonical payload builder
    └── Header injection
```

The module implements `Module` interface with `type = 'layer'` (horizontal concern).