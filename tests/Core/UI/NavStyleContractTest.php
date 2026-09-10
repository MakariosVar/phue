<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\TestCase;

/**
 * Cross-layer style contract between the SSR partial (PhNavigation) and
 * Bootstrap's nav classes.
 *
 * Guarantees that the navigation partial emits standard Bootstrap nav markup
 * that is styled by bootstrap.min.css.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class NavStyleContractTest extends TestCase
{
    private const BS_CSS_PATH = __DIR__ . '/../../../storage/assets/vendor/bootstrap/bootstrap.min.css';

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $path = realpath(self::BS_CSS_PATH);
        $this->assertIsString($path, 'bootstrap.min.css file not found');
        $this->css = (string) file_get_contents($path);
    }

    public function testNavigationWrapperClassIsStyled(): void
    {
        $this->assertStringContainsString('.nav', $this->css, 'Missing .nav selector in Bootstrap');
    }

    public function testNavigationLinkBaseClassIsStyled(): void
    {
        $this->assertStringContainsString('.nav-link', $this->css, 'Missing .nav-link selector in Bootstrap');
    }

    public function testActiveStateClassMatchesPartial(): void
    {
        $this->assertStringContainsString('.nav-link.active', $this->css, 'Missing .nav-link.active selector in Bootstrap');
    }

    public function testDisabledStateClassMatchesPartial(): void
    {
        $this->assertStringContainsString('.nav-link.disabled', $this->css, 'Missing .nav-link.disabled selector in Bootstrap');
    }
}
