<?php

declare(strict_types=1);

namespace Phue\Tests\Modules\RateLimit;

use PHPUnit\Framework\TestCase;
use Phue\Modules\RateLimit\RateLimitModule;

/**
 * Tests for RateLimitModule
 *
 * Verifies Module contract compliance.
 * Tier-1 only: uses Phue\Tests\Support + own logic.
 *
 * @package Phue\Tests\RateLimit
 */
class RateLimitModuleTest extends TestCase
{
    /**
     * Verify module ID and type match scaffold input.
     *
     * @return void
     */
    public function testModuleClassExists(): void
    {
        $m = new RateLimitModule();
        $this->assertSame('rateLimit', $m->id);
        $this->assertSame('layer', $m->type);
    }

    /**
     * Verify the scaffolded class implements the Module contract.
     *
     * @return void
     */
    public function testModuleImplementsContract(): void
    {
        $this->assertInstanceOf(\Phue\Core\Module::class, new RateLimitModule());
    }
}