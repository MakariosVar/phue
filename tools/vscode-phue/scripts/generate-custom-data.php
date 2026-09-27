<?php

/**
 * Generates VS Code HTML Custom Data (schema v1.1) for all PhUI components
 * from the canonical reference at docs/PhUI_Components.md, guaranteed to cover
 * every component shipped in src/Core/UI/Phui/.
 *
 * Components that ship in the framework but lack documentation yet are emitted
 * as minimal tags so autocompletion still works (description flags the gap).
 *
 * Usage:
 *   php tools/vscode-phue/scripts/generate-custom-data.php [path-to-doc] [output-path]
 *
 * Keeps tooling data in sync with the framework; run whenever PhUI components
 * change (add/replace props, rename components).
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 3);
$docPath = $argv[1] ?? $repoRoot . '/docs/PhUI_Components.md';
$outputPath = $argv[2] ?? __DIR__ . '/../custom-data/phui-custom-data.json';
$uiDir = $argv[3] ?? $repoRoot . '/src/Core/UI/Phui';

if (!is_file($docPath)) {
    fwrite(STDERR, sprintf("Document not found: %s\n", $docPath));
    exit(1);
}

/** List of shipped PhUI component tags, e.g. ph-button, spacer. */
function shippedComponents(string $uiDir): array
{
    $tags = [];
    if (is_dir($uiDir)) {
        foreach (glob($uiDir . '/*.phue') ?: [] as $file) {
            $name = basename($file, '.phue');
            $tags[] = strtolower(preg_replace('/(?<!^)[A-Z]/', '-\0', $name)) ?? strtolower($name);
        }
    }
    sort($tags);
    return $tags;
}

$lines = file($docPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read document.\n");
    exit(1);
}

$tags = [];
$current = null;
$currentProps = [];
$currentSlots = [];
$currentEmits = [];
$currentDescription = [];

/**
 * Split a markdown table row into cells. Handles cells containing pipes
 * inside backticks (prop names never do; notes may, so be defensive).
 */
function splitCells(string $row): array
{
    $row = trim($row, '| ');
    $parts = preg_split('/(?<!\\\\)\\|/', $row);
    $cells = [];
    foreach ($parts as $cell) {
        $cells[] = trim(str_replace('\\|', '|', $cell));
    }
    return $cells;
}

$i = 0;
$count = count($lines);

// Which table the parser is currently inside. A component section is allowed to
// hold several tables (props, emits, slots, key maps); only the props table
// feeds `attributes`. Guessing from "any row starting with a backticked word"
// mislabels every slot and emit row as an attribute.
$table = 'none';
$pendingTable = 'none';

while ($i < $count) {
    $line = $lines[$i];

    // Component heading: "### `<ph-xxx>`" or "### `<xxx>`"
    if (preg_match('/^#{2,4}\s+`?<((?:ph-)?[a-z0-9-]+)>`?\s*$/', trim($line), $m)) {
        $name = $m[1];

        if ($current !== null) {
            $tags[] = buildTag($current, $currentProps, $currentSlots, $currentEmits, $currentDescription);
        }
        $current = $name;
        $currentProps = [];
        $currentSlots = [];
        $currentEmits = [];
        $currentDescription = [];
        $table = 'none';
        $pendingTable = 'none';
        $i++;
        continue;
    }

    if ($current === null) {
        $i++;
        continue;
    }

    $trimmed = trim($line);

    // A bolded caption names the table that follows it, e.g. "**Emits**".
    if (preg_match('/^\*\*([A-Za-z]+)\*\*:?\s*$/', $trimmed, $m)) {
        $caption = strtolower($m[1]);
        $pendingTable = match ($caption) {
            'slot', 'slots' => 'slots',
            'emit', 'emits', 'event', 'events' => 'emits',
            'prop', 'props', 'property', 'properties', 'options' => 'props',
            default => 'none',
        };
        $table = 'none';
        $i++;
        continue;
    }

    if (str_starts_with($trimmed, '|')) {
        $cells = splitCells($trimmed);

        // Header row: its first cell names the table, and it supersedes any caption.
        if (!isSeparatorRow($cells)) {
            $first = strtolower(strip_backticks($cells[0] ?? ''));
            $headerKind = match ($first) {
                'prop', 'props', 'property', 'properties', 'option', 'options', 'attribute' => 'props',
                'slot', 'slots' => 'slots',
                'emit', 'emits', 'event', 'events' => 'emits',
                default => null,
            };
            if ($headerKind !== null) {
                $table = $headerKind;
                $pendingTable = 'none';
                $i++;
                continue;
            }
            // Not a header we know: a stray table inside the section, so leave
            // the current mode alone and let data rows fall through below.
        }

        // Separator row: closes the caption-based detection, keeps the mode.
        if (isSeparatorRow($cells)) {
            if ($table === 'none' && $pendingTable !== 'none') {
                $table = $pendingTable;
                $pendingTable = 'none';
            }
            $i++;
            continue;
        }

        // Data row, routed by the table we are currently inside.
        if ($table !== 'none' && preg_match('/^\|\s*`?([A-Za-z][A-Za-z0-9_-]*)`?\s*\|/', $trimmed, $m)) {
            $entry = strip_backticks($m[1]);
            switch ($table) {
                case 'props':
                    if (count($cells) >= 3) {
                        $currentProps[$entry] = [
                            'isBoolean' => stripos($cells[1] ?? '', 'boolean') !== false,
                            'notes' => cleanNotes($cells[3] ?? ($cells[2] ?? '')),
                        ];
                    }
                    break;
                case 'slots':
                    $currentSlots[$entry] = [
                        'name' => $entry,
                        'description' => cleanNotes($cells[1] ?? ''),
                    ];
                    break;
                case 'emits':
                    $currentEmits[] = [
                        'name' => $entry,
                        'payload' => cleanNotes($cells[1] ?? ''),
                    ];
                    break;
            }
            $i++;
            continue;
        }

        $i++;
        continue;
    }

    // Any non-table content closes the table.
    if ($trimmed !== '') {
        $table = 'none';
    }

    // Description: first non-empty, non-table paragraph line inside the section
    if ($currentDescription === [] && $trimmed !== '' && !str_starts_with($trimmed, '|')) {
        $currentDescription[] = $trimmed;
    }

    $i++;
}

if ($current !== null) {
    $tags[] = buildTag($current, $currentProps, $currentSlots, $currentEmits, $currentDescription);
}

/** True for "|---|---|" style rows. */
function isSeparatorRow(array $cells): bool
{
    if ($cells === []) {
        return false;
    }
    foreach ($cells as $cell) {
        if (!preg_match('/^:?-{2,}:?$/', trim($cell))) {
            return false;
        }
    }
    return true;
}

/** "`emit`" -> "emit" */
function strip_backticks(string $value): string
{
    return trim(str_replace('`', '', $value));
}

function cleanNotes(string $notes): string
{
    $notes = preg_replace('/`([^`]*)`/', '\1', $notes);
    $notes = str_replace('\\', '', $notes);
    $notes = preg_replace('/\s+/', ' ', $notes);
    return trim((string) $notes);
}

function buildTag(string $name, array $props, array $slots, array $emits, array $description): array
{
    $tag = ['name' => $name];

    $intro = $description === [] ? '' : cleanNotes(implode(' ', $description));

    // VS Code's custom-data schema has no emits concept, so fold them into the
    // description rather than mislabelling them as attributes.
    $emitted = [];
    foreach ($emits as $emit) {
        $payload = $emit['payload'];
        $isEmpty = $payload === '' || $payload === '—' || $payload === '-' || $payload === '–';
        $emitted[] = $emit['name'] . '(' . ($isEmpty ? '' : $payload) . ')';
    }
    $emitNote = $emitted === [] ? '' : 'Emits: ' . implode(', ', $emitted) . '.';

    $parts = array_values(array_filter([$intro, $emitNote], fn($p) => $p !== ''));
    if ($parts !== []) {
        $tag['description'] = implode(' ', $parts);
    }

    if ($props !== []) {
        $attributes = [];
        foreach ($props as $prop => $meta) {
            $attr = ['name' => camelToKebab($prop)];
            if ($meta['isBoolean']) {
                $attr['values'] = ['true', 'false'];
            }
            if ($meta['notes'] !== '' && $meta['notes'] !== '—' && $meta['notes'] !== '-') {
                $attr['description'] = $meta['notes'];
            }
            $attributes[] = $attr;
        }
        usort($attributes, fn($a, $b) => strcmp($a['name'], $b['name']));
        $tag['attributes'] = $attributes;
    }

    // Slots are not part of the schema either, but tooling that reads custom
    // data does look for them, and an empty list is worse than none.
    if ($slots !== []) {
        $tag['slots'] = array_values($slots);
        usort($tag['slots'], fn($a, $b) => strcmp($a['name'], $b['name']));
    }

    return $tag;
}

function camelToKebab(string $name): string
{
    $kebab = preg_replace('/([a-z0-9])([A-Z])/', '\1-\2', $name);
    return strtolower((string) $kebab);
}

usort($tags, fn($a, $b) => strcmp($a['name'], $b['name']));

foreach (shippedComponents($uiDir) as $shipped) {
    $found = false;
    foreach ($tags as $tag) {
        if ($tag['name'] === $shipped) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $tags[] = [
            'name' => $shipped,
            'description' => 'Shipped in src/Core/UI/Phui/ but not documented in docs/PhUI_Components.md yet.',
        ];
        fwrite(STDERR, sprintf(
            "[warn] %s.phue has no docs section in PhUI_Components.md; emitted minimal tag.\n",
            $shipped
        ));
    }
}
usort($tags, fn($a, $b) => strcmp($a['name'], $b['name']));

$data = [
    'version' => 1.1,
    'tags' => $tags,
];

uksort($data, 'strcmp'); // keep keys ordered: tags, version

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, 'Failed to encode JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

file_put_contents($outputPath, $json . "\n");
printf("Generated %d component tags -> %s\n", count($tags), $outputPath);