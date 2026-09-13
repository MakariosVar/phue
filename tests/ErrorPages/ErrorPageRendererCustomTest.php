<?php

namespace Phue\Tests\ErrorPages;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Phue\Core\ErrorPages\ErrorPageRenderer;

#[\PHPUnit\Framework\Attributes\Group('unit')]
class ErrorPageRendererCustomTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phue_errors_' . uniqid();
        mkdir($this->tempDir . '/App/Errors', 0777, true);
    }

    public function testCustomPageOverridesDefault(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/500.php', '<?php /** @var Throwable $e */ ?><html><body>Custom 500</body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 500);

        $this->assertStringContainsString('Custom 500', $html);
        $this->assertStringNotContainsString('Something went wrong', $html);
    }

    public function testCustomPageReceivesExceptionVariable(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/500.php', '<?php /** @var Throwable $e */ ?><html><body><?= htmlspecialchars($e->getMessage()) ?></body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Message here'), debug: true, statusCode: 500);

        $this->assertStringContainsString('Message here', $html);
    }

    public function testCustomPageReceivesDebugVariable(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/500.php', '<?php /** @var bool $debug */ ?><html><body><?= $debug ? \'ON\' : \'OFF\' ?></body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 500);

        $this->assertStringContainsString('ON', $html);
    }

    public function testCustomPageReceivesStatusCodeVariable(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/404.php', '<?php /** @var int $code */ ?><html><body>Code: <?= $code ?></body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Not found'), debug: true, statusCode: 404);

        $this->assertStringContainsString('Code: 404', $html);
    }

    public function testCustom404UsedFor404Errors(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/404.php', '<?php /** @var Throwable $e */ ?><html><body>Custom 404</body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Not found'), debug: true, statusCode: 404);

        $this->assertStringContainsString('Custom 404', $html);
    }

    public function testFallbackToDefaultWhenNoCustomPage(): void
    {
        // No files in App/Errors/
        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 500);

        $this->assertStringContainsString('Test', $html);
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testFallbackToDefaultWhenNoErrorsDir(): void
    {
        // tempDir exists but no App/Errors/ subdirectory
        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 500);

        $this->assertStringContainsString('Test', $html);
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testDebugPageHasPhueLogo(): void
    {
        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 500);

        $this->assertStringContainsString('Phue.svg', $html);
    }

    public function testProductionPageHasNoPhueLogo(): void
    {
        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: false, statusCode: 500);

        $this->assertStringNotContainsString('Phue.svg', $html);
    }

    public function testDefaultCustomPageFallbacksToDefaults(): void
    {
        file_put_contents($this->tempDir . '/App/Errors/default.php', '<?php /** @var Throwable $e */ ?><html><body>Default custom</body></html>');

        $renderer = new ErrorPageRenderer($this->tempDir);
        $html = $renderer->render(new RuntimeException('Test'), debug: true, statusCode: 999);

        $this->assertStringContainsString('Default custom', $html);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->removeDir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
