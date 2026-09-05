<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\View;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the UI View DTO: template/state/scripts accessors and sane
 * defaults. A framework View must not assume consumer-specific asset paths.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ViewTest extends TestCase
{
    public function testDefaultsHaveNoAssumedScripts(): void
    {
        $view = new View('<div>Hello</div>');

        $this->assertSame('<div>Hello</div>', $view->getTemplate());
        $this->assertSame([], $view->getState());
        $this->assertSame([], $view->getScripts());
    }

    public function testConstructorValuesRoundTrip(): void
    {
        $template = '<ul><li>one</li></ul>';
        $state = ['products' => [['id' => 1]]];
        $scripts = ['/assets/app.js', '/assets/vendor/vue3/vue.esm-browser.js'];

        $view = new View($template, $state, $scripts);

        $this->assertSame($template, $view->getTemplate());
        $this->assertSame($state, $view->getState());
        $this->assertSame($scripts, $view->getScripts());
    }

    public function testEmptyScriptsExplicitlyPassedPreserved(): void
    {
        $view = new View('<div>Hi</div>', [], []);

        $this->assertSame([], $view->getScripts());
    }
}