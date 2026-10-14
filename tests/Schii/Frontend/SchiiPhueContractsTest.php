<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Frontend;

use Phue\Core\UI\PhueTemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the Schii Console .phue components (Phase 3 Rework).
 *
 * These are frontend contract tests (plan §6.4): they parse each .phue view
 * and lock its public surface — component name, props, emits, and the event
 * wiring between Schii.phue and its child components. No browser automation.
 *
 * @package Phue\Tests\Schii\Frontend
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SchiiPhueContractsTest extends TestCase
{
    private const FRONTEND = __DIR__ . '/../../../src/Modules/Schii/Frontend';

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function frontendFilesProvider(): iterable
    {
        yield 'Schii.phue' => ['Schii.phue'];
        yield 'SchiiRecordTable.phue' => ['SchiiRecordTable.phue'];
        yield 'SchiiRecordToolbar.phue' => ['SchiiRecordToolbar.phue'];
    }

    /**
     * Every Schii console view must remain a client-only Vue component:
     * no literal PHP execution leaves PHP tags into the shipped template, and
     * the script block must declare a component name so Vue can resolve it.
     */
    #[DataProvider('frontendFilesProvider')]
    public function testPhueIsClientComponentWithoutPhpLeak(string $file): void
    {
        $template = $this->template($file);
        $this->assertStringNotContainsString('<?', $template, "{$file} template must not leak PHP markup");

        $script = $this->script($file);
        $this->assertMatchesRegularExpression(
            '/export default function mountApp\(\)|export default \{/',
            $script,
            "{$file} must ship an ESM default export (controller page via mountApp, or Options API component)"
        );
    }

    // ── SchiiRecordTable contract ────────────────────────────────────────────

    public function testRecordTableDeclaresExpectedProps(): void
    {
        $script = $this->script('SchiiRecordTable.phue');

        foreach (['fields', 'rows', 'loading', 'page', 'lastPage', 'selected', 'selectable', 'visibleFields', 'sort', 'layout'] as $prop) {
            $this->assertMatchesRegularExpression(
                "/\n\s*{$prop}:\s*\{/m",
                $script,
                "SchiiRecordTable must declare the '{$prop}' prop"
            );
        }
    }

    public function testRecordTableDeclaresExpectedEmits(): void
    {
        $script = $this->script('SchiiRecordTable.phue');

        foreach (['update:selected', 'page-change', 'edit', 'delete-request', 'sort-change'] as $emit) {
            $this->assertStringContainsString("'{$emit}'", $script, "SchiiRecordTable must emit '{$emit}'");
        }
    }

    /**
     * The table sort emits `field:asc` / `field:desc` strings that the parent
     * forwards straight to the REST `sort` query param (D4 protocol).
     */
    public function testRecordTableSortEmitsProtocolString(): void
    {
        $script = $this->script('SchiiRecordTable.phue');

        $this->assertStringContainsString(
            "this.\$emit('sort-change', `\${col.key}:asc`)",
            $script,
            'first click on a sortable header must emit {field}:asc'
        );
        $this->assertStringContainsString(
            "this.\$emit('sort-change', `\${col.key}:\${this.sortState.dir === 'asc' ? 'desc' : 'asc'}`)",
            $script,
            'repeat clicks must toggle between asc and desc'
        );
    }

    public function testRecordTableSelectionEmitsIdArray(): void
    {
        $script = $this->script('SchiiRecordTable.phue');

        $this->assertStringContainsString("this.\$emit('update:selected', [...this.selected, id])", $script);
        $this->assertStringContainsString("this.\$emit('update:selected', [])", $script);
    }

    // ── SchiiRecordToolbar contract ──────────────────────────────────────────

    public function testRecordToolbarDeclaresExpectedProps(): void
    {
        $script = $this->script('SchiiRecordToolbar.phue');

        foreach (['entity', 'selected', 'query', 'fields', 'filters', 'sort', 'pageSize'] as $prop) {
            $this->assertMatchesRegularExpression(
                "/\n\s*{$prop}:\s*\{/m",
                $script,
                "SchiiRecordToolbar must declare the '{$prop}' prop"
            );
        }
    }

    public function testRecordToolbarDeclaresExpectedEmits(): void
    {
        $script = $this->script('SchiiRecordToolbar.phue');

        foreach ([
            'update:query', 'search', 'refresh', 'create', 'schema-diff', 'audit',
            'import-open', 'export-csv', 'export-json', 'bulk-delete',
            'filters-change', 'sort-change', 'page-size-change',
        ] as $emit) {
            $this->assertStringContainsString("'{$emit}'", $script, "SchiiRecordToolbar must emit '{$emit}'");
        }
    }

    /**
     * Sort options must be {label, value} and value must follow the
     * `field:direction` D4 protocol the backend buildQuery understands.
     */
    public function testRecordToolbarSortOptionsUseDirectionProtocol(): void
    {
        $script = $this->script('SchiiRecordToolbar.phue');

        $this->assertStringContainsString("value: `\${f.name}:\${d.value}`", $script);
        $this->assertStringContainsString("{ label: 'ASC', value: 'asc' }", $script);
        $this->assertStringContainsString("{ label: 'DESC', value: 'desc' }", $script);
        $this->assertStringContainsString("{ label: '— No sort —', value: '' }", $script);
    }

    public function testRecordToolbarBuildsFilterObjectFromOnlyFilterableFields(): void
    {
        $script = $this->script('SchiiRecordToolbar.phue');

        $this->assertStringContainsString('this.fields.filter(f => f.filterable)', $script);
        $this->assertStringContainsString(
            "this.\$emit('filters-change', obj);",
            $script,
            'filters must be emitted as a {field: value} object'
        );
    }

    public function testRecordToolbarDoesNotHideRemoveableFilterChipsBehindBadge(): void
    {
        $template = $this->template('SchiiRecordToolbar.phue');

        // The removable filter chip must use PhChip (the only PhUI badge
        // element that supports the `removable`/`@remove` contract).
        $this->assertStringContainsString('<ph-chip', $template);
        $this->assertStringContainsString('removable', $template);
        $this->assertStringContainsString('@remove="removeFilter(i)"', $template);
    }

    // ── Schii.phue wiring contract ───────────────────────────────────────────

    public function testSchiiWiresTableAndToolbarEvents(): void
    {
        $template = $this->template('Schii.phue');

        $this->assertStringContainsString('<schii-record-toolbar', $template);
        $this->assertStringContainsString('@filters-change="onFiltersChange"', $template);
        $this->assertStringContainsString('@sort-change="onSortChange"', $template);
        $this->assertStringContainsString('@page-size-change="onPageSizeChange"', $template);
        $this->assertStringContainsString('@bulk-delete="askBulk(\'delete\')"', $template);

        $this->assertStringContainsString('<schii-record-table', $template);
        $this->assertStringContainsString('@page-change="onPageChange"', $template);
        $this->assertStringContainsString('@sort-change="onSortChange"', $template);
        $this->assertStringContainsString('@delete-request="askDelete"', $template);
        $this->assertStringContainsString('v-model:selected="selected"', $template);
    }

    /**
     * The console must NOT ship the removed hardcoded activate/deactivate
     * bulk actions (D5/S3) — those always returned 422 against the backend.
     */
    public function testSchiiHasNoHardcodedBulkActivateDeactivate(): void
    {
        $template = $this->template('Schii.phue');
        $toolbar = $this->template('SchiiRecordToolbar.phue');

        $this->assertStringNotContainsString('activate', strtolower($template));
        $this->assertStringNotContainsString('activate', strtolower($toolbar));
        $this->assertStringNotContainsString('deactivate', strtolower($toolbar));
    }

    public function testSchiiReadsUrlStateForDeepLinks(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('win.location.search', $script);
        $this->assertStringContainsString('new URLSearchParams', $script);
        $this->assertStringContainsString("out.filter[match[1]] = v;", $script, 'the URL filter[field]=value protocol must be parsed back into a filter object');
        $this->assertStringContainsString('queryParams.entity', $script);
        $this->assertStringContainsString('queryParams.sort', $script);
        $this->assertStringContainsString('history.replaceState', $script);
    }

    public function testSchiiUsesSchemaEnvelopeForFilterableRequiredFields(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('data.fields.filter(f => f.filterable && f.required)', $script);
        $this->assertStringContainsString("`\${apiPrefix}/schema/\${currentEntity.value}`", $script);
    }

    public function testSchiiListRequestCarriesQueryProtocol(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString("page: String(page.value)", $script);
        $this->assertStringContainsString("limit: String(pageSize.value)", $script);
        $this->assertStringContainsString("params.set('q', query.value.trim())", $script);
        $this->assertStringContainsString("params.set('sort', sort.value)", $script);
        $this->assertStringContainsString('params.set(`filter[${k}]`, String(v))', $script);
    }

    // ─────────────────────── Phase D: widget polish ───────────────────────

    public function testSchiiRendersRadioWidgetFromOptions(): void
    {
        $template = $this->template('Schii.phue');

        $this->assertStringContainsString("field.widget === 'radio'", $template);
        $this->assertStringContainsString('<ph-radio', $template);
        $this->assertStringContainsString('v-for="opt in selectOptions(field)"', $template);
        $this->assertStringContainsString(":name=\"'schii-' + field.name\"", $template);
    }

    public function testSchiiRadioIsExcludedFromTextWidget(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString("f.widget === 'radio'", $script);
        $this->assertStringContainsString("const isTextWidget = (f) => !(", $script);
    }

    public function testSchiiRadioDefaultsToFirstOptionOnReset(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString("f.type === 'select' || f.widget === 'select' || f.widget === 'radio'", $script);
    }

    public function testSchiiPasswordWidgetMapsToPasswordInputType(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString("f.widget === 'password'", $script);
        $this->assertStringContainsString("? 'password'", $script);
    }

    public function testSchiiJsonTypeRendersAsTextarea(): void
    {
        $template = $this->template('Schii.phue');

        $this->assertStringContainsString("field.widget === 'textarea' || field.type === 'json'", $template);
        $this->assertStringContainsString(":rows=\"field.type === 'json' ? 5 : 3\"", $template);
    }

    public function testSchiiPassesLayoutToRecordTable(): void
    {
        $template = $this->template('Schii.phue');
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString(':layout="layout"', $template);
        $this->assertStringContainsString("const layout = ref('grid')", $script);
        $this->assertStringContainsString("layout.value = data.layout === 'list' ? 'list' : 'grid'", $script);
    }

    public function testRecordTableRendersCompactListWhenLayoutIsList(): void
    {
        $template = $this->template('SchiiRecordTable.phue');

        $this->assertStringContainsString("v-if=\"layout !== 'list'\"", $template);
        $this->assertStringContainsString("v-else-if=\"layout === 'list'\"", $template);
        $this->assertStringContainsString('list-group', $template);
        $this->assertStringContainsString('listTitle(row)', $template);
        $this->assertStringContainsString('listDetail(row)', $template);
    }

    // ── Relation dropdown contract ───────────────────────────────────────────

    /**
     * A relation field must render through PhSelect3, not PhSelect.
     *
     * PhSelect3 is the component that ships the search event the remote option
     * fetch is built on; PhSelect has no such hook, so binding one here would
     * mean either reimplementing search inside it or losing the feature.
     */
    public function testRelationFieldsRenderThroughPhSelect3(): void
    {
        $template = $this->template('Schii.phue');

        $this->assertStringContainsString('<ph-select3', $template);
        $this->assertStringContainsString('v-if="relationModel(field)"', $template);
    }

    /**
     * The relation branch has to come before the text-input branch, and the
     * text branch has to have become v-else-if. Otherwise a foreign key declared
     * as an integer still paints as a number box and the dropdown never shows.
     */
    public function testRelationBranchPrecedesTheTextInputBranch(): void
    {
        $template = $this->template('Schii.phue');

        $select3 = strpos($template, '<ph-select3');
        $textInput = strpos($template, 'v-else-if="isTextWidget(field)"');

        $this->assertNotFalse($select3, 'the relation select must exist');
        $this->assertNotFalse($textInput, 'the text input must still be guarded by an else-if');
        $this->assertLessThan(
            $textInput,
            $select3,
            'a relation field would render as a plain text input if its branch came second'
        );
    }

    /**
     * The console owns the fetch: PhSelect3 filters locally and reports the typed
     * term, so the request has to be driven from the parent through @search.
     */
    public function testRelationSearchIsWiredToTheOptionsEndpoint(): void
    {
        $template = $this->template('Schii.phue');
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('@search="term => onRelationSearch(field, term)"', $template);
        $this->assertStringContainsString(':options="relationOptions(field.name)"', $template);
        $this->assertStringContainsString('/relation/${encodeURIComponent(slug)}/options', $script);
    }

    public function testRelationOptionsArePreloadedSoTheListIsNotEmptyOnOpen(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('preloadRelationOptions(fields.value)', $script);
        $this->assertStringContainsString('const preloadRelationOptions = (list) =>', $script);
    }

    /**
     * Switching entity must drop the previous entity's option lists, or a field
     * that happens to share a name would show the old table's rows.
     */
    public function testRelationOptionCacheIsClearedOnEntityChange(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('relationOptionCache.value = {};', $script);
    }

    /**
     * Typing fires search on every keystroke; without debouncing that is one
     * request per character against the options endpoint.
     */
    public function testRelationSearchIsDebounced(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('const onRelationSearch = (field, term) =>', $script);
        $this->assertStringContainsString('clearTimeout(relationSearchTimers[field.name])', $script);
    }

    /**
     * A slow earlier response must not overwrite a newer one, and a failed
     * request must not blank a control the user is currently reading.
     */
    public function testRelationFetchGuardsStaleAndFailedResponses(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('if (seq !== relationRequestSeq[field.name] || !res.ok)', $script);
    }

    /**
     * The model class reaches the API as a studly slug; the resolver on the other
     * side turns "order-item" back into OrderItem, and this locks both halves
     * of that round trip.
     */
    public function testEntitySlugDerivationMatchesTheServerResolver(): void
    {
        $script = $this->script('Schii.phue');

        $this->assertStringContainsString('.split(\'\\\\\').pop()', $script);
        $this->assertStringContainsString("replace(/([a-z0-9])([A-Z])/g, '$1-$2')", $script);
        $this->assertStringContainsString('.toLowerCase()', $script);
    }
}