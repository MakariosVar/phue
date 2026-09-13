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

while ($i < $count) {
    $line = $lines[$i];

    // Component heading: "### `<ph-xxx>`" or "### `<spacer>`"
    if (preg_match('/^#{2,4}\s+`?<((?:ph-)?[a-z0-9-]+)>`?\s*$/', trim($line), $m)) {
        $name = $m[1];

        if ($current !== null) {
            $tags[] = buildTag($current, $currentProps, $currentDescription);
        }
        $current = $name;
        $currentProps = [];
        $currentDescription = [];
        $i++;
        continue;
    }

    if ($current === null) {
        $i++;
        continue;
    }

    $trimmed = trim($line);

    // Props table row: "| `prop` | Type | Default | Notes |"
    if (preg_match('/^\|\s*`([A-Za-z][A-Za-z0-9_]*)`\s*\|/', $trimmed, $m)
        && !str_contains($trimmed, 'Prop') // skip header row "| Prop | Type |"
    ) {
        $cells = splitCells($trimmed);
        if (count($cells) >= 3) {
            $propName = $m[1];
            $type = $cells[1] ?? '';
            $notes = $cells[3] ?? '';
            $currentProps[$propName] = [
                'isBoolean' => stripos($type, 'boolean') !== false,
                'notes' => cleanNotes($notes),
            ];
        }
        $i++;
        continue;
    }

    // Description: first non-empty, non-table paragraph line inside the section
    if ($currentDescription === [] && $trimmed !== '' && !str_starts_with($trimmed, '|')) {
        $currentDescription[] = $trimmed;
    }

    $i++;
}

if ($current !== null) {
    $tags[] = buildTag($current, $currentProps, $currentDescription);
}

function cleanNotes(string $notes): string
{
    $notes = preg_replace('/`([^`]*)`/', '\1', $notes);
    $notes = str_replace('\\', '', $notes);
    $notes = preg_replace('/\s+/', ' ', $notes);
    return trim((string) $notes);
}

function buildTag(string $name, array $props, array $description): array
{
    $tag = ['name' => $name];
    if ($description !== []) {
        $tag['description'] = cleanNotes(implode(' ', $description));
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