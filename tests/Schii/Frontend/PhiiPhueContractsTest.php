<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Frontend;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the Phii Console .phue components (Phase 4 Rework).
 *
 * These are frontend contract tests (plan §6.4): they parse each .phue view
 * and lock its public surface — component name, props, emits, and the client
 * side of the plan/preview/apply protocol (D8/D9/D11: project-relative paths,
 * server-side plan recompute, backup default, conflict policy).
 *
 * @package Phue\Tests\Schii\Frontend
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiPhueContractsTest extends TestCase
{
    /**
     * Phii components are owned by the Phii module, not by Schii. A Phii view
     * living under src/Modules/Schii/Frontend would be a cross-module leak:
     * it would make the Schii loader able to serve Phii pages and hide the
     * ownership contract that PhiiRoutingTest locks.
     */
    private const FRONTEND = __DIR__ . '/../../../src/Modules/Phii/Frontend';

    private PhueTemplateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PhueTemplateParser();
    }

    private function structure(string $file): array
    {
        return $this->parser->parseStructure(self::FRONTEND . '/' . $file);
    }

    private function template(string $file): string
    {
        return $this->structure($file)['template'];
    }

    private function script(string $file): string
    {
        $script = $this->structure($file)['script'];
        $this->assertIsString($script, "{$file} must have a <script> block");
        return $script;
    }

    public function testPhiiPageStaysClientSideWithoutPhpLeak(): void
    {
        $template = $this->template('Phii.phue');
        $this->assertStringNotContainsString('<?', $template);

        $script = $this->script('Phii.phue');
        $this->assertMatchesRegularExpression(
            '/export default function mountApp\(\)/',
            $script,
            'Phii.phue must remain a mountApp-style client page'
        );
    }

    public function testPhiiPreviewPanelDeclaresPropsAndEmits(): void
    {
        $script = $this->script('PhiiPreviewPanel.phue');

        foreach (['plan', 'applying', 'selectedPaths', 'conflictPolicy'] as $prop) {
            $this->assertMatchesRegularExpression(
                "/\n\s*{$prop}:\s*\{/m",
                $script,
                "PhiiPreviewPanel must declare the '{$prop}' prop"
            );
        }

        foreach (['apply', 'toggle-path', 'conflict-policy-change'] as $emit) {
            $this->assertStringContainsString("'{$emit}'", $script, "PhiiPreviewPanel must emit '{$emit}'");
        }
    }

    /**
     * The preview panel must render per-file status, expose a per-file
     * include/exclude toggle, and offer a conflict policy — the Phase 4 (V)
     * requirements P5/D9. The backup option is now handled in the parent confirm modal.
     */
    public function testPhiiPreviewPanelLocksConflictAndBackupContract(): void
    {
        $template = $this->template('PhiiPreviewPanel.phue');
        $script = $this->script('PhiiPreviewPanel.phue');

        $this->assertStringContainsString('Overwrite existing', $template);
        $this->assertStringContainsString('Skip existing', $template);
        $this->assertStringContainsString("@click=\"\$emit('apply', { backup: true })\"", $template);
    }

    /**
     * Phii Preview must be able to report plan mutation status (NEW/EXISTS/
     * OVERWRITE) and a per-item content length so the panel can show diffs.
     */
    public function testPhiiPreviewPanelRendersPlanItemStatus(): void
    {
        $template = $this->template('PhiiPreviewPanel.phue');

        foreach (['OVERWRITE', 'EXISTS', 'NEW', 'item.contentLength', 'item.content'] as $needle) {
            $this->assertStringContainsString($needle, $template, "Preview panel must surface {$needle}");
        }
    }

    public function testPhiiPreviewPanelSupportsFilenamePayload(): void
    {
        $template = $this->template('PhiiPreviewPanel.phue');

        $this->assertStringContainsString("{{ isOpen(item.path) ? 'Collapse content' : 'Preview content' }}", $template);
        $this->assertStringContainsString('@click="toggleContent(item.path)"', $template);
    }

    /**
     * Phii.phue must call the schema registry for entity autocomplete (/schema),
     * generate via the phiiPrefix, and not fabricate a success message when
     * generation returned no paths (P-G UX finding).
     */
    public function testPhiiPageUsesSchemaListingForAutocomplete(): void
    {
        $script = $this->script('Phii.phue');

        $this->assertStringContainsString('`${apiBase}/schema`', $script);
        $this->assertStringContainsString('knownEntities.value = Array.isArray(data) ? data : (data.entities || [])', $script);
    }

    public function testPhiiGetsCustomPrefixFromState(): void
    {
        $script = $this->script('Phii.phue');

        $this->assertStringContainsString('initialState.phiiPrefix || \'/phii\'', $script);
        $this->assertStringContainsString('apiBase = initialState.apiPrefix || \'/api/schii\'', $script);
    }

    /**
     * Plan/apply must be driven through a preview round-trip (server-side
     * revision, P7/D9): the page must only send the client-picked subset of
     * paths plus the conflict policy, NOT fresh mutation content.
     */
    public function testPhiiApplySendsPathsAndPolicyNotContent(): void
    {
        $script = $this->script('Phii.phue');

        $this->assertStringContainsString('previewPlan', $script);
        $this->assertStringContainsString('`${phiiPrefix}/preview`', $script);
        $this->assertStringContainsString('`${phiiPrefix}/apply`', $script);
        $this->assertStringContainsString('paths: Array.isArray(selectedPaths.value) ? selectedPaths.value : undefined', $script);
        $this->assertStringContainsString('conflictPolicy: conflictPolicy.value', $script);
        $this->assertStringContainsString('backup:', $script);
        $this->assertStringNotContainsString("'content'", $script, 'apply must NOT ship raw file content from the client');
    }

    public function testPhiiGenerationSendsGeneratorEntityModuleOptions(): void
    {
        $script = $this->script('Phii.phue');

        $this->assertStringContainsString('generator: form.generator,', $script);
        $this->assertStringContainsString('entity: form.entity.trim(),', $script);
        $this->assertStringContainsString('module: form.module.trim() || \'admin\',', $script);
        $this->assertStringContainsString('options,', $script);
    }

    public function testPhiiStructuredOptionsByGenerator(): void
    {
        $template = $this->template('Phii.phue');
        $script = $this->script('Phii.phue');

        // Model type select for 'model' generator
        $this->assertStringContainsString('modelTypeOptions', $script);
        $this->assertStringContainsString('ActiveRecord', $script);
        $this->assertStringContainsString('DataMapper', $script);

        // Driver select for 'migration' generator (includes PostgreSQL)
        $this->assertStringContainsString('driverOptions', $script);
        $this->assertStringContainsString('PostgreSQL', $script);
        $this->assertStringContainsString('pgsql', $script);

        // Actions checkboxes for 'controller' generator
        $this->assertStringContainsString('form.options.actions', $script);
        $this->assertStringContainsString('index', $script);
        $this->assertStringContainsString('store', $script);
        $this->assertStringContainsString('show', $script);
        $this->assertStringContainsString('update', $script);
        $this->assertStringContainsString('delete', $script);

        // Crud uses standard defaults (in template)
        $this->assertStringContainsString('Uses standard defaults', $template);

        // Module has no additional options (in template)
        $this->assertStringContainsString('no additional options', $template);
    }
}