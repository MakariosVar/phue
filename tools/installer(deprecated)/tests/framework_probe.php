#!/usr/bin/env php
<?php

/**
 * Reports what the framework's src/Modules actually contains.
 *
 * The installer discovers modules itself, so a test that asserted the result
 * against a list written here would only be asserting that two copies of the
 * same assumption agree. This reads the class files directly instead: the id a
 * module declares, the directory it lives in, and the class that implements it.
 * Three separate names, none of them derivable from the others — rateLimit lives
 * in RateLimit and is implemented by RateLimitModule — and every one of them
 * has to come out right for an install to be correct.
 *
 * Usage: php framework_probe.php <path/to/src/Modules>
 * Output: one "id\tdir\tclass" record per module, ordered by directory — the
 * order the installer walks src/Modules in, which is also the order the
 * interactive checklist lists them in.
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');

if (!is_dir($root)) {
    fwrite(STDERR, "no such directory: {$root}\n");
    exit(1);
}

$records = [];

foreach (scandir($root) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
        continue;
    }

    $class = null;
    $code = '';

    foreach (scandir($root . '/' . $entry) ?: [] as $file) {
        if (!str_ends_with($file, '.php')) {
            continue;
        }

        $source = (string) file_get_contents($root . '/' . $entry . '/' . $file);

        if (preg_match('~^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)~m', $source, $m) !== 1) {
            continue;
        }

        if (preg_match('~extends\s+Module\b~', $source) !== 1) {
            continue;
        }

        $class = $m[1];
        $code = $source;
        break;
    }

    // A directory with no Module subclass is tooling, not a module, and has no
    // id to be selected by.
    if ($class === null) {
        continue;
    }

    $id = '';

    if (preg_match('~public\s+string\s+\$id\s*=\s*\'([^\']*)\'~', $code, $m) === 1) {
        $id = $m[1];
    }

    $records[] = [$id !== '' ? $id : $entry, $entry, $class];
}

// Ordered by directory, because that is the order the installer discovers them
// in and the order the interactive checklist shows. Sorting by id would agree
// with it only for as long as no module is named after something other than its
// own directory.
usort($records, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

foreach ($records as $record) {
    echo implode("\t", $record), PHP_EOL;
}
