<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Frontend;

use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhueComponent;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the ComponentLoaderRegistry and PhueComponentLoader.
 *
 * These classes were extended with load() + scriptUrl() + contentFor() so that
 * PhUI components (src/Core/UI/Phui/*.phue) can be resolved globally across
 * both App and Schii frontends without hardcoding component paths.
 *
 * @package Phue\Tests\Core\Frontend
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class ComponentLoaderRegistryTest extends TestCase
{
    private string $tmpDir;
    private string $componentsDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phue_registry_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->componentsDir = $this->tmpDir . '/components';
        mkdir($this->componentsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
    }

    public function testLoadReturnsFirstLoaderComponent(): void
    {
        $this->writePhue('PhButton', '<template><button /></template><script>export default {}</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $result = $registry->load('PhButton');

        $this->assertInstanceOf(PhueComponent::class, $result);
        $this->assertSame('<button />', trim($result->view->getTemplate()));
    }

    public function testLoadReturnsNullWhenNoLoaderHasComponent(): void
    {
        $registry = new ComponentLoaderRegistry();

        $this->assertNull($registry->load('NoSuchComponent'));
    }

    public function testLoadSkipsFailedLoaderAndReturnsFromNext(): void
    {
        $this->writePhue('PhButton', '<template><button /></template><script>export default {}</script>');
        $goodLoader = new PhueComponentLoader($this->componentsDir);

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => throw new \RuntimeException('boom'));
        $registry->addLoader(fn () => $goodLoader);

        $result = $registry->load('PhButton');

        $this->assertInstanceOf(PhueComponent::class, $result);
    }

    public function testScriptUrlReturnsHashedAssetUrl(): void
    {
        $script = 'export default { name: "PhButton" }';
        $this->writePhue('PhButton', '<template><button /></template><script>' . $script . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $url = $registry->scriptUrl('PhButton');

        $this->assertStringStartsWith('/assets/phue/PhButton.', $url);
        $this->assertStringEndsWith('.phue.js', $url);
        // Verify hash is correct
        $hash = substr(sha1($script), 0, 8);
        $this->assertStringContainsString($hash, $url);
    }

    public function testScriptUrlReturnsNullWhenNotFound(): void
    {
        $registry = new ComponentLoaderRegistry();

        $this->assertNull($registry->scriptUrl('NoSuchComponent'));
    }

    public function testScriptUrlSkipsFailedLoader(): void
    {
        $script = 'export default { name: "PhButton" }';
        $this->writePhue('PhButton', '<template><button /></template><script>' . $script . '</script>');
        $goodLoader = new PhueComponentLoader($this->componentsDir);

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => throw new \RuntimeException('boom'));
        $registry->addLoader(fn () => $goodLoader);

        $this->assertNotNull($registry->scriptUrl('PhButton'));
    }

    public function testScriptForReturnsScriptWhenHashMatches(): void
    {
        $script = 'export default { name: "PhButton" }';
        $this->writePhue('PhButton', '<template><button /></template><script>' . $script . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $hash = substr(sha1($script), 0, 8);
        $result = $registry->scriptFor('PhButton', $hash);

        $this->assertSame($script, $result);
    }

    public function testScriptForReturnsNullWhenHashMismatch(): void
    {
        $this->writePhue('PhButton', '<template><button /></template><script>export default {}</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $result = $registry->scriptFor('PhButton', 'deadbeef');

        $this->assertNull($result);
    }

    public function testScriptForReturnsNullWhenNotFound(): void
    {
        $registry = new ComponentLoaderRegistry();

        $this->assertNull($registry->scriptFor('NoSuchComponent', 'aaaaaaa'));
    }

    public function testContentForReturnsRawScript(): void
    {
        $script = 'export default { name: "PhButton" }';
        $this->writePhue('PhButton', '<template><button /></template><script>' . $script . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);

        $this->assertSame($script, $loader->contentFor('PhButton'));
    }

    public function testContentForReturnsNullWhenNoScript(): void
    {
        $this->writePhue('PhNoScript', '<template><div /></template>');
        $loader = new PhueComponentLoader($this->componentsDir);

        $this->assertNull($loader->contentFor('PhNoScript'));
    }

    public function testContentForReturnsNullWhenNotFound(): void
    {
        $loader = new PhueComponentLoader($this->componentsDir);

        $this->assertNull($loader->contentFor('NoSuchComponent'));
    }

    public function testMultipleLoadersResolvesByPriority(): void
    {
        // PhButton in first loader, PhInput in second (under $this->tmpDir so tearDown cleans it).
        $this->writePhue('PhButton', '<template><button /></template><script>export default {}</script>');
        $firstDir = $this->componentsDir;
        $secondDir = $this->tmpDir . '/components_second';
        mkdir($secondDir, 0777, true);
        $this->writePhue('PhInput', '<template><input /></template><script>export default {}</script>', $secondDir);

        $firstLoader = new PhueComponentLoader($firstDir);
        $secondLoader = new PhueComponentLoader($secondDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $firstLoader);
        $registry->addLoader(fn () => $secondLoader);

        // PhButton resolved by first loader.
        $this->assertInstanceOf(PhueComponent::class, $registry->load('PhButton'));
        // PhInput resolved by second loader.
        $this->assertInstanceOf(PhueComponent::class, $registry->load('PhInput'));
    }

    private function writePhue(string $name, string $content, ?string $dir = null): void
    {
        $targetDir = $dir ?? $this->componentsDir;
        file_put_contents($targetDir . '/' . $name . '.phue', $content);
    }

    private const BTN_SCRIPT = 'export default { name: "PhButton" }';
    private const BTN_TEMPLATE = '<button class="btn">Go</button>';

    public function testModuleUrlReturnsHashedModuleUrl(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $url = $registry->moduleUrl('PhButton');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('/assets/phue/PhButton.', $url);
        $this->assertStringEndsWith('.phue.js', $url);

        $expectedHash = substr(sha1(PhueComponentLoader::buildModule(self::BTN_TEMPLATE, self::BTN_SCRIPT)), 0, 8);
        $this->assertStringContainsString($expectedHash, $url);
    }

    public function testModuleUrlReturnsNullWhenNotFound(): void
    {
        $registry = new ComponentLoaderRegistry();

        $this->assertNull($registry->moduleUrl('NoSuchComponent'));
    }

    public function testModuleUrlSkipsFailedLoader(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $goodLoader = new PhueComponentLoader($this->componentsDir);

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => throw new \RuntimeException('boom'));
        $registry->addLoader(fn () => $goodLoader);

        $this->assertNotNull($registry->moduleUrl('PhButton'));
    }

    public function testModuleForReturnsModuleWhenHashMatches(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $expected = PhueComponentLoader::buildModule(self::BTN_TEMPLATE, self::BTN_SCRIPT);
        $hash = substr(sha1($expected), 0, 8);

        $this->assertSame($expected, $registry->moduleFor('PhButton', $hash));
    }

    public function testModuleForReturnsNullWhenHashMismatch(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $loader = new PhueComponentLoader($this->componentsDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $loader);

        $this->assertNull($registry->moduleFor('PhButton', 'deadbeef'));
    }

    public function testModuleForReturnsNullWhenNotFound(): void
    {
        $registry = new ComponentLoaderRegistry();

        $this->assertNull($registry->moduleFor('NoSuchComponent', 'aaaaaaa'));
    }

    public function testModuleForSkipsFailedLoader(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $goodLoader = new PhueComponentLoader($this->componentsDir);

        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => throw new \RuntimeException('boom'));
        $registry->addLoader(fn () => $goodLoader);

        $expected = PhueComponentLoader::buildModule(self::BTN_TEMPLATE, self::BTN_SCRIPT);
        $hash = substr(sha1($expected), 0, 8);

        $this->assertSame($expected, $registry->moduleFor('PhButton', $hash));
    }

    public function testModuleForAndModuleUrlResolveByPriority(): void
    {
        $this->writePhue('PhButton', '<template>' . self::BTN_TEMPLATE . '</template><script>' . self::BTN_SCRIPT . '</script>');
        $firstDir = $this->componentsDir;
        $secondDir = $this->tmpDir . '/components_second';
        mkdir($secondDir, 0777, true);
        $this->writePhue('PhInput', '<template><input /></template><script>export default { name: "PhInput" }</script>', $secondDir);

        $firstLoader = new PhueComponentLoader($firstDir);
        $secondLoader = new PhueComponentLoader($secondDir);
        $registry = new ComponentLoaderRegistry();
        $registry->addLoader(fn () => $firstLoader);
        $registry->addLoader(fn () => $secondLoader);

        $expectedBtn = PhueComponentLoader::buildModule(self::BTN_TEMPLATE, self::BTN_SCRIPT);
        $this->assertSame($expectedBtn, $registry->moduleFor('PhButton', substr(sha1($expectedBtn), 0, 8)));

        $expectedInput = PhueComponentLoader::buildModule('<input />', 'export default { name: "PhInput" }');
        $this->assertSame($expectedInput, $registry->moduleFor('PhInput', substr(sha1($expectedInput), 0, 8)));
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