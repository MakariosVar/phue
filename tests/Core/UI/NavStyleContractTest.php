<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\TestCase;

/**
 * Cross-layer style contract between the SSR partial (PhNavigation) and the shared stylesheet.
 *
 * Guarantees that every CSS class emitted by the partial is actually declared in
 * phue.css, so a server-rendered navigation element can never silently lose its
 * styling after a rename on either side.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class NavStyleContractTest extends TestCase
{
    private const CSS_PATH = __DIR__ . '/../../../storage/assets/styles/phue.css';

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();

        $path = realpath(self::CSS_PATH);
        $this->assertIsString($path, 'phue.css file not found');
        $this->css = (string) file_get_contents($path);
    }

    public function testNavigationWrapperClassIsStyled(): void
    {
        $this->assertMatchesRegularExpression('/\.ph-nav\b/', $this->css, 'Missing .ph-nav selector');
    }

    public function testNavigationLinkBaseClassIsStyled(): void
    {
        $this->assertMatchesRegularExpression('/\.ph-nav__link/', $this->css, 'Missing .ph-nav__link selector');
    }

    public function testActiveStateClassMatchesPartial(): void
    {
        // The partial emits `<a class="ph-nav__link is-active">`.
        $this->assertMatchesRegularExpression(
            '/\.ph-nav__link\.is-active/',
            $this->css,
            'Missing .ph-nav__link.is-active compound selector (partial emits "is-active")',
        );
    }

    public function testDisabledStateClassMatchesPartial(): void
    {
        // The partial emits `<span class="ph-nav__link is-disabled">`.
        $this->assertMatchesRegularExpression(
            '/\.ph-nav__link\.is-disabled/',
            $this->css,
            'Missing .ph-nav__link.is-disabled compound selector (partial emits "is-disabled")',
        );
    }
}