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

    public function testStylesDefaultToEmptyArray(): void
    {
        $view = new View('<div>Hi</div>', [], []);

        $this->assertSame([], $view->getStyles());
    }

    public function testStylesRoundTripAsLastNamedParameter(): void
    {
        $styles = ['/assets/app/styles/main.css', '/assets/app/styles/extra.css'];

        $view = new View(
            template: '<div>Hi</div>',
            state: [],
            scripts: ['/assets/app.js'],
            inlineScript: null,
            html: null,
            styles: $styles
        );

        $this->assertSame($styles, $view->getStyles());
        $this->assertNull($view->getInlineScript());
        $this->assertNull($view->getHtml());
    }

    public function testAppendingStylesParamPreservesHistoricalPositionalContract(): void
    {
        // $styles was append-added at the END (after $html) on purpose so that
        // the pre-styles positional layout (template, state, scripts, inlineScript, html)
        // keeps working. Locking that order here is a hard regression guard.
        $view = new View(
            '<div>Hi</div>',
            ['k' => 'v'],
            ['/assets/app.js'],
            "console.log('kept');",
            '<p>html-first</p>'
        );

        $this->assertSame("console.log('kept');", $view->getInlineScript());
        $this->assertSame('<p>html-first</p>', $view->getHtml());
        $this->assertSame([], $view->getStyles());
    }

    public function testStylesDoNotLeakIntoTemplateStateOrScripts(): void
    {
        $view = new View(
            template: '<div>Hi</div>',
            state: ['page' => 'styled'],
            styles: ['/assets/app/only.css']
        );

        $this->assertSame(['/assets/app/only.css'], $view->getStyles());
        $this->assertSame([], $view->getScripts());
        $this->assertSame('styled', $view->getState()['page']);
        $this->assertSame('<div>Hi</div>', $view->getTemplate());
    }
}