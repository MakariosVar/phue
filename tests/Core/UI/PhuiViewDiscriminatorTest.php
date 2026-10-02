<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use Phue\Tests\Support\TestComponentLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PhuiViewDiscriminatorTest — every `.phue` View must be flagged as a Vue template.
 *
 * `View::isVueTemplate()` is what stops the renderer from serving a component's
 * template as page content. Without the flag, `ComponentRenderer` cannot tell a
 * finished HTML string from a Vue template, and the only thing left to it is to
 * inline both — which is correct for the first and catastrophic for the second,
 * because the client then hydrates Vue against its own source text and the page
 * never becomes interactive.
 *
 * The flag is set in exactly two places, and this test exists because a change in
 * either of them fails **silently**: nothing throws, the page still returns 200,
 * and every other assertion in the suite stays green. It is a one-line omission
 * with a total outage behind it.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class PhuiViewDiscriminatorTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    public function testTheParserFlagsItsViews(): void
    {
        $component = (new PhueTemplateParser())->parse(
            self::PHUI_DIR . '/PhCollapse.phue',
            []
        );

        self::assertTrue(
            $component->view->isVueTemplate(),
            'PhueTemplateParser builds its View from a .phue, so it must set the flag'
        );
    }

    public function testTheLoaderFlagsItsViews(): void
    {
        $loader = TestComponentLoader::make(self::PHUI_DIR);
        $component = $loader->load('PhCollapse', []);

        self::assertTrue(
            $component->view->isVueTemplate(),
            'PhueComponentLoader builds its View from a .phue, so it must set the flag'
        );
    }

    public function testAHandBuiltViewIsNotFlagged(): void
    {
        // The default matters as much as the set case: a caller passing finished
        // HTML must keep getting it inlined, which is a documented behaviour
        // (ComponentRendererTest::testRendersShellWithInlinedTemplateTitleAndState).
        $view = new \Phue\Core\UI\View('<div>plain</div>');

        self::assertFalse($view->isVueTemplate());
    }

    public function testTheFlagDefaultsToFalseSoPositionalCallersAreUnaffected(): void
    {
        // The new constructor parameter was appended last on purpose. Had it been
        // inserted mid-list, the four positional callers
        // (ComponentRenderer::renderView x2, PhueComponentLoader, PhueTemplateParser)
        // would have shifted their arguments silently.
        $reflection = new \ReflectionMethod(\Phue\Core\UI\View::class, '__construct');
        $parameters = $reflection->getParameters();
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $parameters);

        self::assertSame(
            'isVueTemplate',
            end($names),
            'the discriminator must be the LAST parameter, or every positional caller shifts'
        );

        $flag = $parameters[array_key_last($names)];
        self::assertTrue(
            $flag->isDefaultValueAvailable(),
            'the discriminator needs a default so the four existing positional callers stay valid'
        );
        self::assertFalse(
            $flag->getDefaultValue(),
            'and that default must be false, so a hand built View is never treated as a template'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function componentProvider(): array
    {
        $cases = [];
        foreach (glob(self::PHUI_DIR . '/*.phue') ?: [] as $file) {
            $cases[basename($file, '.phue')] = [$file];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('componentProvider')]
    public function testEveryShippedComponentLoadsFlagged(string $file): void
    {
        $loader = TestComponentLoader::make(self::PHUI_DIR);
        $component = $loader->load(basename($file, '.phue'), []);

        self::assertTrue(
            $component->view->isVueTemplate(),
            basename($file, '.phue') . ' must load flagged as a Vue template, or a failed SSR '
            . 'render would serve this component\'s directives to the browser as text'
        );
    }
}