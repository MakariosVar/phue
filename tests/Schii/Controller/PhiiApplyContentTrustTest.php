<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Controller;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Modules\Schii\Config\SchiiConfig;
use Phue\Modules\Schii\Controller\PhiiController;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P7 — the Phii `apply` endpoint must NEVER trust client-supplied file content.
 *
 * The plan (P7, decision D7) moves content derivation fully server-side. Until that
 * lands, these tests demonstrate the vulnerability: a fabricated plan writes
 * arbitrary files and tampered content lands on disk verbatim.
 *
 * @package Phue\Tests\Schii\Controller
 */
#[Group('unit')]
final class PhiiApplyContentTrustTest extends TestCase
{
    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_phii_apply_' . uniqid();
        @mkdir($this->dump, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dump)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dump, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->dump);
        }
        parent::tearDown();
    }

    private function controller(): PhiiController
    {
        $config = SchiiConfig::fromArray([
            'apiPrefix' => '/api/schii',
            'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
            'outputRoot' => $this->dump,
        ]);

        return new PhiiController(
            $this->createMock(\Phue\Core\UI\ComponentRenderer::class),
            new SchemaRegistry(),
            $config,
            $this->createMock(Application::class)
        );
    }

    public function testApplyRejectsFabricatedArbitraryFile(): void
    {
        $response = $this->controller()->apply(new Request('POST', '/phii/apply', [], [
            'plan' => [[
                'path' => 'Core/Exploit.php',
                'content' => "<?php // attacker-controlled code landing outside any generator target\n",
            ]],
        ]));

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'A fabricated {path, content} entry served verbatim is a write-primitive; apply must reject it.'
        );
        $this->assertFileDoesNotExist(
            $this->dump . '/Core/Exploit.php',
            'Arbitrary client-supplied content must never be written by apply.'
        );
    }

    public function testApplyNeverLandsTamperedContentOnDisk(): void
    {
        $tampered = "<?php // tampered: replaced content that no generator would ever produce\n";

        $response = $this->controller()->apply(new Request('POST', '/phii/apply', [], [
            'plan' => [[
                'path' => 'App/Models/Widget.php',
                'content' => $tampered,
            ]],
        ]));

        $onDisk = file_exists($this->dump . '/App/Models/Widget.php')
            ? (string) file_get_contents($this->dump . '/App/Models/Widget.php')
            : '';

        $this->assertStringNotContainsString(
            'tampered',
            $onDisk,
            'If apply writes at all, the bytes must come from a server-side recomputation, ' .
            'never from the request body.'
        );

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'Serving a bare {path, content} plan (no plan identity / server derivation) must not succeed.'
        );
    }
}