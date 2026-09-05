<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contract tests for the .phue Single File Component parser.
 *
 * Locks the block-extraction grammar, the PHP state executor, the title/state
 * plumbing and the security validation rules around inline module scripts.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhueTemplateParserTest extends TestCase
{
    private const DIR = __DIR__ . '/Fixtures';

    private PhueTemplateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0777, true);
        }

        $this->parser = new PhueTemplateParser();
    }

    protected function tearDown(): void
    {
        foreach (glob(self::DIR . '/*.phue') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function fixture(string $name, string $content): string
    {
        $path = self::DIR . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function testParsesComponentWithTitleStateTemplateAndInlineScript(): void
    {
        $path = $this->fixture('basic.phue', <<<'PHUE'
<php>
return [
    'title' => 'Products',
    'state' => ['products' => [['id' => 1, 'name' => 'Widget A']]],
];
</php>

<template>
<div>
    <h2>Catalog</h2>
    <li v-for="p in products">{{ p.name }}</li>
</div>
</template>

<script>
import { createApp, ref } from '/assets/vendor/vue3/vue.esm-browser.js';
createApp({ setup() { return { products: ref(window.__INITIAL_STATE__.products) }; } }).mount('#app');
</script>
PHUE);

        $component = $this->parser->parse($path);

        $this->assertSame('Products', $component->title);
        $this->assertSame(
            [['id' => 1, 'name' => 'Widget A']],
            $component->view->getState()['products']
        );
        $this->assertStringContainsString('<h2>Catalog</h2>', $component->view->getTemplate());
        $this->assertStringContainsString(
            "createApp({ setup() { return { products: ref(window.__INITIAL_STATE__.products) }; } }).mount('#app');",
            $component->view->getInlineScript()
        );
    }

    public function testProvidesDefaultTitleWhenPhpBlockOmitsIt(): void
    {
        $path = $this->fixture('no-title.phue', <<<'PHUE'
<php>
return ['state' => []];
</php>
<template><div>Hello</div></template>
<script>console.log('ok');</script>
PHUE);

        $this->assertSame('Phue App', $this->parser->parse($path)->title);
    }

    public function testMissingPhpBlockYieldsEmptyState(): void
    {
        $path = $this->fixture('no-php.phue', <<<'PHUE'
<template><div>Static</div></template>
<script>console.log('ok');</script>
PHUE);

        $component = $this->parser->parse($path);

        $this->assertSame([], $component->view->getState());
        $this->assertSame([], $component->view->getScripts());
        $this->assertSame("console.log('ok');", $component->view->getInlineScript());
    }

    public function testMissingTemplateBlockThrows(): void
    {
        $path = $this->fixture('no-template.phue', "<php>return ['state' => []];</php>\n<script>x</script>");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('template');

        $this->parser->parse($path);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parse(self::DIR . '/does-not-exist.phue');
    }

    #[DataProvider('malformedProvider')]
    public function testPhpBlockTypeErrorsSurfaceAsThrowables(string $name, string $content): void
    {
        $path = $this->fixture($name, $content);

        $this->expectException(\Throwable::class);

        $this->parser->parse($path);
    }

    public static function malformedProvider(): iterable
    {
        yield 'missing return' => ['no-return.phue', "<php>state = ['a' => 1];</php>\n<template><div>x</div></template>"];
        yield 'parse error' => ['broken.phue', "<php>return '</php>\n<template><div>x</div></template>"];
    }

    public function testInlineScriptContainingScriptTerminatorThrows(): void
    {
        $path = $this->fixture('breakout.phue', <<<'PHUE'
<php>return ['state' => []];</php>
<template><div>x</div></template>
<script>
const s = '</script>';
</script>
PHUE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Security');

        $this->parser->parse($path);
    }

    public function testInlineScriptContainingBareScriptTerminatorPrefixThrows(): void
    {
        $path = $this->fixture('breakout-prefix.phue', <<<'PHUE'
<php>return ['state' => []];</php>
<template><div>x</div></template>
<script>
const s = '</script maybe>';
</script>
PHUE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Security');

        $this->parser->parse($path);
    }

    public function testNestedScriptInsideTemplateIsNotMistakenForModuleScript(): void
    {
        $path = $this->fixture('nested.phue', <<<'PHUE'
<template>
<div>
    <span id="tpl">Hi</span>
    <script>inlineTemplateHelper();</script>
</div>
</template>
<script>console.log('REAL MODULE');</script>
PHUE);

        $component = $this->parser->parse($path);

        $this->assertStringContainsString('<div>', $component->view->getTemplate());
        $this->assertStringContainsString(
            '<script>inlineTemplateHelper();</script>',
            $component->view->getTemplate()
        );
        $this->assertSame('console.log(\'REAL MODULE\');', $component->view->getInlineScript());
    }

    public function testTemplateIsTrimmedAndScriptIsTrimmed(): void
    {
        $path = $this->fixture('whitespace.phue', <<<'PHUE'
<template>

<div>   spaced   </div>

</template>
<script>

console.log('x');

</script>
PHUE);

        $component = $this->parser->parse($path);

        $this->assertSame("<div>   spaced   </div>", $component->view->getTemplate());
        $this->assertSame("console.log('x');", $component->view->getInlineScript());
    }
}