<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\TestCase;

/**
 * Static safety contracts for client-rendered Phui components whose bugs only
 * surface in the browser (CSP / Bootstrap variable scoping).
 *
 * These lock two regressions:
 *
 *  - PhFileInput previews: the shell ships `Content-Security-Policy:
 *    img-src 'self' data:`, so `URL.createObjectURL()` blob URLs are blocked
 *    and image previews silently never render. Previews must be produced with
 *    FileReader data: URLs, and non-images must fall back to a typed
 *    Bootstrap icon.
 *
 *  - PhConfirmDialog: Bootstrap declares the `--bs-modal-*` custom properties
 *    on `.modal`. A hand-rolled fixed backdrop without the `.modal` class
 *    leaves `.modal-content` transparent and its header/body/footer without
 *    padding. The dialog must reuse the real Bootstrap modal structure.
 *
 * @package Phue\Tests\Core\UI
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhuiClientSafetyContractTest extends TestCase
{
    private const PHUI_DIR = __DIR__ . '/../../../src/Core/UI/Phui';

    private function structure(string $name): array
    {
        $file = self::PHUI_DIR . "/{$name}.phue";
        self::assertFileExists($file, "{$name}.phue must exist");

        return (new PhueTemplateParser())->parseStructure($file);
    }

    private function all(string $name): string
    {
        $structure = $this->structure($name);

        return ($structure['template'] ?? '') . "\n" . ($structure['script'] ?? '');
    }

    public function testFileInputPreviewsUseDataUrlsNotBlobUrls(): void
    {
        $all = $this->all('PhFileInput');

        $this->assertStringContainsString(
            'readAsDataURL',
            $all,
            'PhFileInput previews must use FileReader data: URLs (blob: is blocked by the CSP).',
        );
        $this->assertStringNotContainsString(
            'createObjectURL',
            $all,
            'PhFileInput must not use URL.createObjectURL(): the shell CSP only allows img-src self data:.',
        );
    }

    public function testFileInputFallsBackToTypedIcons(): void
    {
        $structure = $this->structure('PhFileInput');

        $this->assertStringContainsString(
            'iconFor(file)',
            $structure['template'] ?? '',
            'PhFileInput must render a typed icon for non-image files.',
        );
        $this->assertStringContainsString(
            'iconFor(',
            $structure['script'] ?? '',
            'PhFileInput must define the iconFor() helper.',
        );
    }

    public function testConfirmDialogReusesBootstrapModalStructure(): void
    {
        $template = $this->structure('PhConfirmDialog')['template'] ?? '';

        foreach (['class="modal fade show d-block"', 'modal-dialog', 'modal-content', 'modal-backdrop fade show'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $template,
                "PhConfirmDialog must reuse Bootstrap's modal markup ({$needle}); " .
                'without .modal the --bs-modal-* variables are undefined.',
            );
        }
    }

    public function testConfirmDialogDoesNotHandRollItsOwnBackdrop(): void
    {
        $script = $this->structure('PhConfirmDialog')['script'] ?? '';

        $this->assertStringNotContainsString(
            'backdropStyle',
            $script,
            'PhConfirmDialog must not define a custom backdropStyle overlay; use Bootstrap .modal + .modal-backdrop.',
        );
    }
}
