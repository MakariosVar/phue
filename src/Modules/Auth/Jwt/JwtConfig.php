<?php

declare(strict_types=1);

namespace Phue\Modules\Auth\Jwt;

/**
 * JWT Configuration holder
 * 
 * @package Phue\Modules\Auth\Jwt
 */
class JwtConfig
{
    /**
     * @var string Secret key used for signing and verifying tokens
     */
    public string $secret;

    /**
     * @var int Token expiration time in seconds (default: 3600 = 1 hour)
     */
    public int $ttl = 3600;

    /**
     * @var string JWT algorithm to use (default: HS256)
     */
    public string $algorithm = 'HS256';

    /**
     * @var string Issuer claim (optional)
     */
    public ?string $issuer = null;

    /**
     * @var string Audience claim (optional)
     */
    public ?string $audience = null;

    /**
     * Constructor
     * 
     * @param string $secret Secret key for JWT signing
     * @param array<string, mixed> $options Configuration options
     */
    public function __construct(string $secret, array $options = [])
    {
        $this->secret = $secret;
        
        if (isset($options['ttl'])) {
            $this->ttl = (int)$options['ttl'];
        }
        
        if (isset($options['algorithm'])) {
            $this->algorithm = $options['algorithm'];
        }
        
        if (isset($options['issuer'])) {
            $this->issuer = $options['issuer'];
        }
        
        if (isset($options['audience'])) {
            $this->audience = $options['audience'];
        }
    }
}