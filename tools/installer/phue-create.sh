#!/usr/bin/env bash
#
# Phue Project Creator — full framework installer.
#
# Creates a new Phue application from the framework source:
#   - framework core (bin, config, public, src/Core, docs, docker, ssr)
#   - the modules you pick, their tests, their config
#   - generated composer.json / config/app.php / phpunit.xml
#
# The module list is never written down here. It is scanned out of src/Modules,
# the same directories the framework itself ships, so a module added to the
# framework is installable the day it lands and one that is renamed or deleted
# cannot leave a dead entry behind. See load_modules().
#
# Usage:
#   phue-create.sh <project-dir> [--template=<name>] [options]
#   phue-create.sh <project-dir> --modules=auth,rest
#   phue-create.sh                       (interactive: template, then modules)
#
# From a URL:
#   bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web

set -euo pipefail

# ---------------------------------------------------------------- constants

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if (( BASH_VERSINFO[0] < 4 )); then
    printf 'Error: bash 4 or newer is required (found %s).\n' "${BASH_VERSION:-unknown}" >&2
    exit 1
fi

# Field separator for the scanner's records. Not a tab: tab is IFS whitespace,
# so read() would collapse the empty fields that mean "this module has no test
# directory" and silently shift every column after it.
readonly US=$'\x1f'
readonly CUSTOM_TEMPLATE="custom"

declare -a SPLIT=()

# ---------------------------------------------------------------- output

C_RESET=''; C_BOLD=''; C_DIM=''; C_CYAN=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_BLUE=''

if [[ -t 1 && -z "${NO_COLOR:-}" && "${TERM:-dumb}" != "dumb" ]]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_CYAN=$'\033[36m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'
    C_RED=$'\033[31m'; C_BLUE=$'\033[34m'
fi

die() {
    printf '%s✗%s %s\n' "$C_RED" "$C_RESET" "$1" >&2
    [[ $# -gt 1 ]] && printf '  %s\n' "$2" >&2
    exit 1
}

note() { printf '  %s·%s %s\n' "$C_CYAN" "$C_RESET" "$1"; }
warn() { printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
ok()   { printf '  %s✓%s %s\n' "$C_GREEN" "$C_RESET" "$1"; }

need() {
    command -v "$1" >/dev/null 2>&1 || die "$2"
}

join_comma() {
    local IFS=,
    echo "$*"
}

# contains <needle> <item>...
#
# Membership by value, not by array name. The obvious indirect version —
# `${!name[@]}` for the indices and `${name[$i]}` for the element — is not
# merely awkward, it does not work: bash resolves the element to the array's
# *name*, so the comparison never matches anything and the dependency loop that
# uses it keeps re-adding what it already holds until the terminal is killed.
# Passing the values costs one expansion and cannot be wrong.
contains() {
    local needle="$1"
    shift

    local item
    for item in "$@"; do
        [[ "$item" == "$needle" ]] && return 0
    done

    return 1
}

# split_csv <csv> — fills the global SPLIT array.
#
# read -a rather than word-splitting an expansion: a bare "*" in a template has
# to stay a literal asterisk, and unquoted expansion would hand it to the
# globber, which would answer with the repository's file listing.
split_csv() {
    local -a raw=() clean=()
    local index

    # Split on commas and on spaces: ids arrive both ways depending on whether
    # they came from a template, a command line, or a list this script built.
    IFS=$' ,\t' read -r -a raw <<< "$1"

    for (( index = 0; index < ${#raw[@]}; index++ )); do
        raw[$index]="${raw[$index]//[[:space:]]/}"
        [[ -n "${raw[$index]}" ]] && clean+=("${raw[$index]}")
    done

    SPLIT=(${clean[@]+"${clean[@]}"})
    return 0
}

usage() {
    cat <<'EOF'
Phue Project Creator

Usage: phue-create.sh [project-dir] [options]

Options:
  --template=<name>   Use a predefined template (see --list-templates)
  --modules=a,b,c     Custom module selection (non-interactive)
  --list-templates    List the available templates and exit
  --list-modules      List every module this framework ships, and exit
  --dry-run           Print exactly what would be written, write nothing
  --repo=<path>       Path to the Phue framework source (default: this repo)
  --force             Allow creating inside a non-empty directory
  -h, --help          Show this help

Interactive mode:
  Run without --template/--modules. First pick a template, or choose
  "Custom" to go one level deeper and pick modules one by one.
  up/down moves, space toggles, a selects all, n selects none,
  enter confirms, esc goes back to the template list.

From a URL:
  bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web

Run --list-templates and --list-modules to see what this framework ships.
EOF
}

# ---------------------------------------------------------------- module scan
#
# One PHP process, one pass, no autoloader: this runs against a source checkout
# that may have no vendor/ at all, so the module classes are read as text rather
# than loaded. What it reads, and why each thing is read from where it is:
#
#   id        public string $id       the name the installer uses for the module
#   type      public string $type     'layer' | 'kit', from the Module contract
#   requires  public array $requires  the dependency edges, from the contract
#   class     namespace + class name the constant config/app.php must list
#   tests     tests/<Dir>, tests/Modules/<Dir>, … first that holds a *Test.php
#   config    config/<id>.php, config/<Dir>.php — punctuation-insensitive match
#   suggest   ext-redis / ext-swoole, when the module's own code reaches for them
#
# The last three are conventions, not contracts, and each has a legitimate empty
# answer: a module with no test directory is a module with no tests, a module
# with no config file configures itself, a module that does not touch Redis does
# not suggest ext-redis. None of them may fail the install, so none of them do.

PHP_SCAN="$(cat <<'PHP_SCAN_EOF'
declare(strict_types=1);

/**
 * Describes every directory under src/Modules.
 *
 * Emits one record per directory, fields separated by 0x1F:
 *
 *   id, kind, class, dir, tests, config, type, requires, suggest, summary
 *
 * kind is 'module' for a directory holding a Module subclass and 'tooling' for
 * one that does not. Phii is the framework's code generator: every project gets
 * it, it declares no Module, and it is therefore invisible to any scan that only
 * looks for Module subclasses — which is exactly why it is found and labelled
 * here instead of being written into this script as a hardcoded exception.
 */

$us   = "\x1f";
$root = rtrim($argv[1] ?? '', '/');
$src  = $root . '/src/Modules';

if (!is_dir($src)) {
    fwrite(STDERR, "no src/Modules under {$root}\n");
    exit(1);
}

/** The module class in a directory, or null if the directory holds none. */
$moduleClass = static function (string $dir): ?array {
    foreach (scandir($dir) ?: [] as $entry) {
        if (!str_ends_with($entry, '.php')) {
            continue;
        }

        $code = (string) file_get_contents($dir . '/' . $entry);

        if (preg_match('~^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)~m', $code, $m) !== 1) {
            continue;
        }

        if (preg_match('~extends\s+Module\b~', $code) !== 1) {
            continue;
        }

        preg_match('~^namespace\s+([^;]+);~m', $code, $ns);

        return [$m[1], trim(($ns[1] ?? '') . '\\' . $m[1], '\\')];
    }

    return null;
};

/**
 * One line a person can choose a module by.
 *
 * The whole class docblock up to the first @tag is considered, not just its
 * first line: the conventions in this framework put "Class FooModule" on line
 * one and the actual sentence on line two, and stopping at the blank line after
 * the first would leave every module described as "Auth module".
 */
$summary = static function (string $code, string $class): string {
    $pattern = '~/\*\*(.*?)\*/\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+'
        . preg_quote($class, '~') . '\b~s';

    if (preg_match($pattern, $code, $m) !== 1) {
        return '';
    }

    $boilerplate = static fn(string $line): bool =>
        preg_match('~^(?:Class|Interface|Trait)\s+\w+$~i', $line) === 1
        || strcasecmp($line, $class) === 0;

    $paragraphs = [];
    $current = [];

    foreach (preg_split('~\R~', $m[1]) ?: [] as $line) {
        $line = trim(preg_replace('~^\s*\*~', '', $line));

        if ($line === '') {
            if ($current !== []) {
                $paragraphs[] = $current;
                $current = [];
            }
            continue;
        }

        // An @tag ends the description; the sentence after a tag describes the tag.
        if (str_starts_with($line, '@')) {
            break;
        }

        if (!$boilerplate($line)) {
            $current[] = $line;
        }
    }

    if ($current !== []) {
        $paragraphs[] = $current;
    }

    // "HTTP Response Signing Module." on its own line, followed by a sentence
    // that says the same thing properly. Dropped so the list shows the sentence.
    if (count($paragraphs) > 1
        && preg_match('~^.{0,60}\bmodule\.:?$~iu', $paragraphs[0][0]) === 1) {
        array_shift($paragraphs);
    }

    $text = trim(implode(' ', array_map(
        static fn(array $p): string => implode(' ', $p),
        $paragraphs
    )));

    if ($text === '') {
        return '';
    }

    // A list line, not a paragraph. Cut at a sentence boundary where one falls
    // inside the budget; otherwise at a word. Counted in characters, not bytes:
    // the /u modifier makes "." walk whole code points, so a description can end
    // on an em dash without the string being cut inside it.
    $limit = 110;

    if (preg_match('~^.{0,' . $limit . '}~us', $text, $m) === 1 && $m[0] !== $text) {
        $cut = $m[0];

        if (preg_match('~^.{0,' . $limit . '}[.!?](?=\s|$)~us', $text, $m) === 1) {
            return trim($m[0]);
        }

        return rtrim(preg_replace('~\s+\S*$~u', '', $cut), " ,;:") . '…';
    }

    return $text;
};

/** Single-quoted strings inside a PHP array literal. */
$strings = static function (?string $literal): array {
    if ($literal === null || trim($literal) === '') {
        return [];
    }

    preg_match_all("~'([^']*)'~", $literal, $m);

    return array_values(array_filter(array_map('trim', $m[1]), static fn($v) => $v !== ''));
};

$normalise = static fn(string $s): string => strtolower(str_replace(['-', '_', ' '], '', $s));

$hasTests = static function (string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), 'Test.php')) {
            return true;
        }
    }

    return false;
};

/**
 * The tests directory for a module, or an empty string.
 *
 * Both roots are indexed case- and punctuation-insensitively because the
 * framework does not spell them consistently: the module directory is DevTool
 * while its tests live in tests/DevTools, and two modules keep theirs under
 * tests/Modules because they were added after the convention settled. A lookup
 * table built from the filesystem answers all of those, and a rename on either
 * side is picked up with no change here at all.
 *
 * A directory that exists but holds no *Test.php is not a testsuite. An empty
 * tests/Modules/Rest left behind by a move would otherwise generate a
 * <testsuite> that runs nothing while hiding the real one.
 */
$findTests = static function (string $moduleDir, string $id) use ($root, $normalise, $hasTests): string {
    $index = [];

    foreach (['tests', 'tests/Modules'] as $testsRoot) {
        foreach (scandir($root . '/' . $testsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $testsRoot . '/' . $entry;

            // tests/ is searched before tests/Modules/, so a module with both
            // keeps the one the repository's own phpunit.xml would use.
            if (isset($index[$normalise($entry)]) || !$hasTests($root . '/' . $relative)) {
                continue;
            }

            $index[$normalise($entry)] = $relative;
        }
    }

    foreach ([$moduleDir, $id] as $candidate) {
        if (isset($index[$normalise($candidate)])) {
            return $index[$normalise($candidate)];
        }
    }

    return '';
};

$findConfig = static function (array $candidates) use ($root, $normalise): string {
    $wanted = array_map($normalise, $candidates);

    foreach ((scandir($root . '/config') ?: []) as $entry) {
        if (str_ends_with($entry, '.php') && in_array($normalise(basename($entry, '.php')), $wanted, true)) {
            return 'config/' . $entry;
        }
    }

    return '';
};

/** PHP extensions the module's own code reaches for. */
$suggests = static function (string $dir): array {
    $found = [];

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match('#(?:^|\n)\s*use\s+\\\\?Redis\b#', $code) === 1
            || preg_match('~\bnew\s+\\\\?Redis\s*\(~', $code) === 1
            || preg_match('~\\\\Redis::class~', $code) === 1) {
            $found['ext-redis'] = true;
        }

        if (preg_match('#(?:^|\n)\s*use\s+\\\\?Swoole\\\\#', $code) === 1
            || preg_match('~\\\\Swoole\\\\[A-Za-z]~', $code) === 1) {
            $found['ext-swoole'] = true;
        }
    }

    return array_values(array_filter(
        ['ext-redis', 'ext-swoole'],
        static fn(string $ext): bool => isset($found[$ext])
    ));
};

$entries = scandir($src) ?: [];
sort($entries, SORT_STRING);

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..' || !is_dir($src . '/' . $entry)) {
        continue;
    }

    $found = $moduleClass($src . '/' . $entry);

    if ($found === null) {
        // Tooling still gets its tests: Phii's test suite is part of the
        // framework's own test runner, and dropping it would mean every project
        // the installer creates is missing a suite the framework has.
        echo implode($us, [
            '',
            'tooling',
            '',
            $entry,
            $findTests($entry, $entry),
            '',
            'tooling',
            '',
            '',
            '',
        ]) . PHP_EOL;
        continue;
    }

    [$class, $fqcn] = $found;
    $code = (string) file_get_contents($src . '/' . $entry . '/' . $class . '.php');

    preg_match('~public\s+string\s+\$id\s*=\s*\'([^\']*)\'~', $code, $idMatch);
    $id = ($idMatch[1] ?? '') !== '' ? $idMatch[1] : $entry;

    preg_match('~public\s+string\s+\$type\s*=\s*\'([^\']*)\'~', $code, $typeMatch);
    preg_match('~public\s+array\s+\$requires\s*=\s*\[([^\]]*)\]~s', $code, $reqMatch);

    echo implode($us, [
        $id,
        'module',
        $fqcn,
        $entry,
        $findTests($entry, $id),
        $findConfig([$id, $entry]),
        $typeMatch[1] ?? 'layer',
        implode(',', $strings($reqMatch[1] ?? '')),
        implode(',', $suggests($src . '/' . $entry)),
        $summary($code, $class),
    ]) . PHP_EOL;
}
PHP_SCAN_EOF
)"

# Written descriptions for the few modules whose docblock carries no summary.
# The docblock is the source of truth; this only fills the gaps it leaves, and
# its absence is not an error — those modules fall back to a generic line.
declare -gA MODULE_DESC_OVERRIDES=(
    [events]="Event bus over Redis Streams, plus SSE event streaming"
    [server]="Swoole persistent server adapter: HTTP, workers, WebSockets"
)

# App/ files a module brings with it.
#
# This is installer policy rather than framework truth. src/Modules describes
# the framework; which sample files of the example App/ belong to which module
# is a choice made here, because nothing in src/Modules says so. The value is
# the set of App/ files, and COMPANION_REQUIRES below is the set of modules that
# must also be selected for one of them to work — AuthController annotates
# routes with #[RateLimit], so copying it into a project without the RateLimit
# module would scaffold a controller that fatals the first time it is reflected
# on, which is the worst possible time to find out.
declare -gA MODULE_COMPANIONS=(
    [auth]="App/Models/User.php App/Controllers/AuthController.php"
    [rest]="App/Controllers/ApiController.php"
)

declare -gA COMPANION_REQUIRES=(
    ["App/Controllers/AuthController.php"]="ratelimit"
)

# ---------------------------------------------------------------- load modules

declare -a MODULE_IDS=()
declare -Ag MODULE_KIND=() MODULE_CLASS=() MODULE_DIR=() MODULE_TESTS=()
declare -Ag MODULE_CONFIG=() MODULE_TYPE=() MODULE_REQUIRES=() MODULE_SUGGEST=()
declare -Ag MODULE_SUMMARY=()

scan_modules() {
    php -r "$PHP_SCAN" "$REPO_ROOT"
}

# Humanise an id into a last resort description: "rateLimit" becomes
# "Rate limit module".
humanise() {
    local text="$1"
    text="${text//-/ }"
    text="${text//_/ }"
    text="$(printf '%s' "$text" | sed -E 's/([a-z0-9])([A-Z])/\1 \2/g')"
    printf '%s%s module' "$(printf '%s' "$text" | cut -c1 | tr '[:lower:]' '[:upper:]')" \
        "$(printf '%s' "$text" | cut -c2-)"
}

load_modules() {
    local output id kind class dir tests config type requires suggest summary
    local -a record

    output="$(scan_modules)" \
        || die "Could not read the modules in '$REPO_ROOT/src/Modules'." \
               "Is '$REPO_ROOT' a Phue checkout?"

    while IFS="$US" read -r id kind class dir tests config type requires suggest summary; do
        record=("$id" "$kind" "$class" "$dir" "$tests" "$config" "$type" "$requires" "$suggest" "$summary")

        if [[ "${record[1]}" == "tooling" ]]; then
            local tool="${record[3]}"
            MODULE_IDS+=("$tool")
            MODULE_KIND["$tool"]="tooling"
            MODULE_DIR["$tool"]="src/Modules/$tool"
            MODULE_TESTS["$tool"]="${record[4]}"
            MODULE_TYPE["$tool"]="tooling"
            MODULE_SUMMARY["$tool"]="framework tooling, installed with every project"
            continue
        fi

        MODULE_IDS+=("$id")
        MODULE_KIND["$id"]="module"
        MODULE_CLASS["$id"]="$class"
        MODULE_DIR["$id"]="src/Modules/$dir"
        MODULE_TESTS["$id"]="$tests"
        MODULE_CONFIG["$id"]="$config"
        MODULE_TYPE["$id"]="$type"
        MODULE_REQUIRES["$id"]="$requires"
        MODULE_SUGGEST["$id"]="$suggest"

        if [[ -n "${MODULE_DESC_OVERRIDES[$id]:-}" ]]; then
            MODULE_SUMMARY["$id"]="${MODULE_DESC_OVERRIDES[$id]}"
        elif [[ -n "$summary" ]]; then
            MODULE_SUMMARY["$id"]="$summary"
        else
            MODULE_SUMMARY["$id"]="$(humanise "$id")"
        fi
    done <<< "$output"

    (( ${#MODULE_IDS[@]} > 0 )) || die "No modules found in '$REPO_ROOT/src/Modules'."
    return 0
}

module_ids() {
    local id
    for id in ${MODULE_IDS[@]+"${MODULE_IDS[@]}"}; do
        [[ "${MODULE_KIND[$id]}" == "module" ]] && printf '%s\n' "$id"
    done
    return 0
}

tooling_ids() {
    local id
    for id in ${MODULE_IDS[@]+"${MODULE_IDS[@]}"}; do
        [[ "${MODULE_KIND[$id]}" == "tooling" ]] && printf '%s\n' "$id"
    done
    return 0
}

describe_module() {
    local id="$1"
    printf '%s' "${MODULE_SUMMARY[$id]}"
    [[ -n "${MODULE_REQUIRES[$id]:-}" ]] && printf '%s  (needs %s)' "$C_DIM" "${MODULE_REQUIRES[$id]}"
    return 0
}

# The canonical id for what someone typed, or nothing.
#
# Module ids are whatever the module declared: rateLimit and http-signing are not
# guesses, they are what $id says in the class. Someone typing `--modules=ratelimit`
# or `rate-limit` means those, and rejecting it teaches them nothing except that
# the tool does not know its own module names. Case and -/_ differences are the
# only things forgiven; an unknown word is still an error.
resolve_module_id() {
    local raw="$1" candidate

    [[ -z "$raw" ]] && return 1
    [[ -n "${MODULE_CLASS[$raw]:-}" ]] && { printf '%s' "$raw"; return 0; }

    for candidate in $(module_ids); do
        if [[ "${candidate,,}" == "${raw,,}" ]]; then
            printf '%s' "$candidate"
            return 0
        fi
    done

    local needle="${raw,,}"
    needle="${needle//-/}"
    needle="${needle//_/}"

    for candidate in $(module_ids); do
        local comparable="${candidate,,}"
        comparable="${comparable//-/}"
        comparable="${comparable//_/}"

        if [[ "$comparable" == "$needle" ]]; then
            printf '%s' "$candidate"
            return 0
        fi
    done

    return 1
}

# ---------------------------------------------------------------- templates
#
# A template is a module selection with a name and a reason. The schema is kept
# deliberately small: anything a template could express but not also justify is
# a template nobody should trust six months from now.
#
#   name        required, matches the filename
#   title       short label for the interactive picker
#   description one line, shown under the title
#   order       sort key, lower first; equal keys fall back to name
#   tags        free-form, shown in --list-templates
#   modules     ids to install, or ["*"] for every module the scan found
#
# "*" is what keeps fullstack honest. Ten hardcoded ids mean the eleventh module
# is silently missing from the one template whose entire promise is "everything",
# and nothing would report it.

declare -a TEMPLATE_NAMES=()
declare -Ag TEMPLATE_TITLE=() TEMPLATE_DESC=() TEMPLATE_TAGS=() TEMPLATE_MODULES=()

PHP_TEMPLATES="$(cat <<'PHP_TEMPLATES_EOF'
declare(strict_types=1);

/**
 * Emits one record per templates/*.json:
 *   order, name, title, description, tags, modules
 *
 * Sorted by order then name, so the picker shows a deliberate progression
 * (smallest first, catch-all last) rather than whatever the filesystem returns.
 */

$us  = "\x1f";
$dir = rtrim($argv[1] ?? '', '/');
$rows = [];

foreach ((scandir($dir) ?: []) as $entry) {
    if (!str_ends_with($entry, '.json')) {
        continue;
    }

    $path = $dir . '/' . $entry;
    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data)) {
        fwrite(STDERR, "Template {$path} is not valid JSON.\n");
        exit(1);
    }

    $name = (string) ($data['name'] ?? basename($entry, '.json'));
    $mods = $data['modules'] ?? [];

    if (!is_array($mods)) {
        fwrite(STDERR, "Template '{$name}': 'modules' must be a list of ids.\n");
        exit(1);
    }

    $rows[] = [
        (int) ($data['order'] ?? 100),
        $name,
        (string) ($data['title'] ?? $name),
        (string) ($data['description'] ?? ''),
        // Formatted here rather than in each consumer: every screen and listing
        // wants them the same way, and a comma-joined string only works for the
        // one place that remembers to replace the separator.
        is_array($data['tags'] ?? null)
            ? implode(' ', array_map(static fn($t): string => '#' . $t, $data['tags']))
            : '',
        implode(',', array_map('strval', $mods)),
    ];
}

usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

foreach ($rows as $row) {
    echo implode($us, $row) . PHP_EOL;
}
PHP_TEMPLATES_EOF
)"

load_templates() {
    local output order name title description tags modules

    output="$(php -r "$PHP_TEMPLATES" "$TEMPLATES_DIR")" \
        || die "Could not read the templates in '$TEMPLATES_DIR'."

    while IFS="$US" read -r order name title description tags modules; do
        TEMPLATE_NAMES+=("$name")
        TEMPLATE_TITLE["$name"]="$title"
        TEMPLATE_DESC["$name"]="$description"
        TEMPLATE_TAGS["$name"]="$tags"
        TEMPLATE_MODULES["$name"]="$modules"
    done <<< "$output"

    validate_templates
}

# A template naming a module the framework does not ship is a bug in the
# template, and it has to surface when the template is read rather than as a
# confusing "module not found" after half an install has been written.
validate_templates() {
    local name raw resolved
    local -a unknown=()

    for name in ${TEMPLATE_NAMES[@]+"${TEMPLATE_NAMES[@]}"}; do
        split_csv "${TEMPLATE_MODULES[$name]}"

        for raw in ${SPLIT[@]+"${SPLIT[@]}"}; do
            [[ "$raw" == "*" ]] && continue
            resolved="$(resolve_module_id "$raw" || true)"

            [[ -z "$resolved" ]] && unknown+=("$name → $raw")
        done
    done

    if (( ${#unknown[@]} > 0 )); then
        printf '%s✗%s Templates reference modules this framework does not ship:\n' \
            "$C_RED" "$C_RESET" >&2
        printf '    %s\n' "${unknown[@]}" >&2
        printf '  Available: %s\n' "$(join_comma $(module_ids))" >&2
        exit 1
    fi

    return 0
}

template_count() {
    printf '%s' "${#TEMPLATE_NAMES[@]}"
}

# The selection a template stands for, with "*" already expanded.
template_selection() {
    local name="$1" list="${TEMPLATE_MODULES[$1]}"

    if [[ "$list" == "*" || "$list" == *,\* ]]; then
        list="$(join_comma $(module_ids))"
    fi

    printf '%s' "$list"
}

describe_selection() {
    local index
    local -a list=()

    split_csv "$1"
    list=(${SPLIT[@]+"${SPLIT[@]}"})

    if (( ${#list[@]} == 0 )); then
        printf 'no modules — core framework only'
        return 0
    fi

    for (( index = 0; index < ${#list[@]}; index++ )); do
        (( index > 0 )) && printf ', '
        printf '%s' "${list[$index]}"
    done

    return 0
}

list_templates() {
    local name width=0 count
    count="$(template_count)"

    printf '\n  %sTemplates%s  %s%d%s in %s\n\n' \
        "$C_BOLD" "$C_RESET" "$C_DIM" "$count" "$C_RESET" "$TEMPLATES_DIR"

    for name in ${TEMPLATE_NAMES[@]+"${TEMPLATE_NAMES[@]}"}; do
        (( ${#name} > width )) && width=${#name}
    done

    for name in ${TEMPLATE_NAMES[@]+"${TEMPLATE_NAMES[@]}"}; do
        printf '  %s%-*s%s  %s%s%s\n' \
            "$C_GREEN" "$width" "$name" "$C_RESET" "$C_BOLD" "${TEMPLATE_TITLE[$name]}" "$C_RESET"
        printf '  %s%*s  %s%s%s\n' \
            "$C_DIM" "$width" "" "$C_RESET" "${TEMPLATE_DESC[$name]}" "$C_RESET"
        printf '  %s%*s%s  %s%s%s\n\n' \
            "$C_DIM" "$width" "" "$C_RESET" \
            "$C_DIM" "$(describe_selection "$(template_selection "$name")")" "$C_RESET"
    done

    printf '  %s%-*s%s  %s%s%s\n' \
        "$C_GREEN" "$width" "$CUSTOM_TEMPLATE" "$C_RESET" \
        "$C_BOLD" "Custom" "$C_RESET"
    printf '  %s%*s%s  %s%s%s\n' \
        "$C_DIM" "$width" "" "$C_RESET" "$C_DIM" "Pick the modules yourself, interactively." "$C_RESET"
    printf '\n'
}

list_modules() {
    local id width=0 count
    local -a modules=()
    mapfile -t modules < <(module_ids)
    count=${#modules[@]}

    printf '\n  %sModules%s  %s%d%s in %s\n\n' \
        "$C_BOLD" "$C_RESET" "$C_DIM" "$count" "$C_RESET" "$REPO_ROOT/src/Modules"

    for id in "${modules[@]}"; do
        (( ${#id} > width )) && width=${#id}
    done

    for id in "${modules[@]}"; do
        printf '  %s%-*s%s %s%-6s%s %s\n' \
            "$C_GREEN" "$width" "$id" "$C_RESET" \
            "$C_DIM" "${MODULE_TYPE[$id]}" "$C_RESET" \
            "$(describe_module "$id")"

        if [[ -n "${MODULE_SUGGEST[$id]}" ]]; then
            printf '  %s%*s  %ssuggests %s%s\n' \
                "$C_DIM" "$width" "" "$C_RESET" "${MODULE_SUGGEST[$id]}" "$C_RESET"
        fi
        if [[ -z "${MODULE_TESTS[$id]}" ]]; then
            printf '  %s%*s  %sno testsuite in this framework%s\n' \
                "$C_DIM" "$width" "" "$C_RESET" "$C_RESET"
        fi
        printf '\n'
    done

    local tool
    for tool in $(tooling_ids); do
        printf '  %s%-*s%s %s%-6s%s %s%s/%s%s\n' \
            "$C_BLUE" "$width" "$tool" "$C_RESET" \
            "$C_DIM" "tooling" "$C_RESET" \
            "$C_DIM" "src/Modules" "$tool" "$C_RESET"
        printf '  %s%*s  %s%s%s\n\n' "$C_DIM" "$width" "" "$C_RESET" \
            "${MODULE_SUMMARY[$tool]}" "$C_RESET"
    done
}

# ---------------------------------------------------------------- selection

declare -a SELECTED_MODULES=()
TEMPLATE_NAME="custom"
TEMPLATE_DESC_USED="custom module selection"

is_selected() {
    contains "$1" ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}
}

select_modules() {
    local m resolved requested=0

    split_csv "$1"

    for m in ${SPLIT[@]+"${SPLIT[@]}"}; do
        requested=$(( requested + 1 ))
        m="${m,,}"
        resolved="$(resolve_module_id "$m" || true)"

        if [[ -z "$resolved" ]]; then
            die "Unknown module '$m'." "Available: $(join_comma $(module_ids))"
        fi

        is_selected "$resolved" || SELECTED_MODULES+=("$resolved")
    done

    # A template that selects nothing is a valid template — it is how "core only"
    # is spelled. Typing `--modules=` with nothing after it is a mistake, and the
    # caller checks for that one itself: refusing here would make the empty
    # template uninstallable, and installing everything instead would be worse.
    (( requested == 0 )) && return 0

    (( ${#SELECTED_MODULES[@]} > 0 )) || die "Empty module selection."
    return 0
}

# The selection with every transitive requirement folded in, as a csv.
#
# Only ids the scan actually found are added. A module whose $requires names
# something this framework does not ship is reported by resolve_dependencies()
# rather than silently dropped here, because silently is the whole problem.
expand_dependencies() {
    local id r changed=1
    local -a list=()

    split_csv "$1"

    for id in ${SPLIT[@]+"${SPLIT[@]}"}; do
        list+=("$id")
    done

    while (( changed )); do
        changed=0

        for id in ${list[@]+"${list[@]}"}; do
            split_csv "${MODULE_REQUIRES[$id]:-}"

            for r in ${SPLIT[@]+"${SPLIT[@]}"}; do
                [[ -n "${MODULE_CLASS[$r]:-}" ]] || continue
                contains "$r" ${list[@]+"${list[@]}"} && continue
                list+=("$r")
                changed=1
            done
        done
    done

    printf '%s' "$(join_comma ${list[@]+"${list[@]}"})"
}

# Report a requirement the framework cannot satisfy. This is a framework bug,
# not a user error, so it is a warning and never a refusal: refusing would leave
# the user with no way to install the module they asked for.
warn_unsatisfiable_requires() {
    local id r
    local -a seen=()

    for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        split_csv "${MODULE_REQUIRES[$id]:-}"

        for r in ${SPLIT[@]+"${SPLIT[@]}"}; do
            [[ -z "$r" ]] && continue
            [[ -n "${MODULE_CLASS[$r]:-}" ]] && continue
            contains "$id/$r" ${seen[@]+"${seen[@]}"} && continue
            seen+=("$id/$r")
            warn "Module '$id' requires '$r', which this framework does not ship."
        done
    done

    return 0
}

# Pull in what the selection cannot run without, and say so. A silent addition is
# worse than none: the user reads a summary that does not match what they chose
# and stops believing every other line of the output.
resolve_dependencies() {
    warn_unsatisfiable_requires

    local expanded m
    expanded="$(expand_dependencies "$(join_comma ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"})")"
    split_csv "$expanded"

    for m in ${SPLIT[@]+"${SPLIT[@]}"}; do
        is_selected "$m" && continue
        SELECTED_MODULES+=("$m")
        printf '  %s+%s %s%s%s %sadded automatically — %s requires it%s\n' \
            "$C_YELLOW" "$C_RESET" "$C_BOLD" "$m" "$C_RESET" \
            "$C_DIM" "$(module_requiring "$m")" "$C_RESET"
    done

    return 0
}

# Which selected module is responsible for pulling $1 in. Purely cosmetic, but
# "auth added automatically" is an answer and "auth added automatically because
# rest needs it" is an explanation the user can act on.
module_requiring() {
    local wanted="$1" id r

    for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        split_csv "${MODULE_REQUIRES[$id]:-}"

        for r in ${SPLIT[@]+"${SPLIT[@]}"}; do
            if [[ "$r" == "$wanted" ]]; then
                printf '%s' "$id"
                return 0
            fi
        done
    done

    printf '%s' 'a dependency'
    return 0
}

# A whitespace-separated list of module ids, sorted into the order the framework
# lists them in. Spaces, not commas: the caller assigns it to an array, and an
# array assignment splits on IFS — handing it "auth,rest" produces one element
# named "auth,rest" rather than two modules.
#
# Dependencies arrive appended and out of order, so an unsorted list puts auth
# wherever it happened to be discovered. Sorting into scan order keeps
# config/app.php and phpunit.xml byte-identical between two installs of the same
# set — the property that makes a scaffold diffable — and keeps the preview in
# the interactive module screen in step with the summary the user gets at the
# end. A preview in a different order from the result is a preview that makes the
# user check twice.
order_ids() {
    local id
    local -a wanted=() ordered=()

    split_csv "$1"
    wanted=(${SPLIT[@]+"${SPLIT[@]}"})

    for id in $(module_ids); do
        contains "$id" ${wanted[@]+"${wanted[@]}"} && ordered+=("$id")
    done

    printf '%s' "${ordered[*]-}"
}

normalize_selection_order() {
    SELECTED_MODULES=($(order_ids "$(join_comma ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"})"))
    return 0
}

# ---------------------------------------------------------------- terminal ui
#
# Two screens, one draw loop.
#
# Redrawing means moving back to the row the previous frame started on and
# erasing downwards — not counting lines back up from wherever the cursor
# happens to be. Every frame ends with a newline, so the cursor always finishes
# one row below the frame: ask the terminal where it is, and the next frame is
# drawn *below* the last one, which is how a full-screen menu ends up scrolling
# itself off the terminal a keypress at a time. The row the first frame starts
# on is therefore captured once, and every later frame is drawn from there.
#
# That row is asked for with ESC[6n, one round trip, before the first frame. A
# terminal that does not answer leaves the fallback: move up by the number of
# lines the last frame printed, which is wrong only for a frame that wrapped.

UI_WIDTH=80
UI_TOP=''
UI_LINES=0
UI_ACTIVE=0
UI_TTY_SAVED=''
UI_KEY=''

ui_width() {
    local cols="${COLUMNS:-}"

    [[ -z "$cols" ]] && cols="$(tput cols 2>/dev/null || true)"
    [[ "$cols" =~ ^[0-9]+$ ]] || cols=80

    (( cols < 60 )) && cols=60
    UI_WIDTH=$cols
}

# Raw mode for the duration of the interactive session.
#
# This is not decoration. In the terminal's ordinary line mode a keypress is
# not delivered until it is followed by a newline, so an arrow key — three bytes
# and no newline — sits in the line buffer while the program waits for a byte
# that will never come, and `read -n1` times out instead of returning. What the
# user experiences is a menu where the first arrow works and the rest do
# nothing, which is worse than no keyboard handling at all because it looks
# like the program ignoring them.
#
# Echo goes off with it: without that, every key is drawn on the screen as well
# as interpreted, and the menu ends up with stray characters in it.
#
# The saved settings are restored on the way out, including on Ctrl-C, or the
# user's shell is left without an echo.
ui_raw_on() {
    UI_TTY_SAVED="$(stty -g 2>/dev/null || true)"
    stty -echo -icanon min 1 time 0 2>/dev/null || true
}

ui_raw_off() {
    [[ -n "$UI_TTY_SAVED" ]] && stty "$UI_TTY_SAVED" 2>/dev/null
    UI_TTY_SAVED=''
    return 0
}

# The row the first frame will start on, or an empty string if the terminal does
# not report it. Runs before any frame is drawn, so it cannot eat a keystroke the
# user has already typed.
ui_anchor() {
    local char seq='' answered=0

    printf '\033[6n'

    while IFS= read -rsn1 -t 0.5 char 2>/dev/null; do
        seq+="$char"

        if [[ "$char" == "R" ]]; then
            answered=1
            break
        fi
    done

    if (( answered )) && [[ "$seq" =~ \[([0-9]+)\;[0-9]+R ]]; then
        UI_TOP="${BASH_REMATCH[1]}"
    fi

    return 0
}

ui_begin() {
    [[ -t 0 ]] \
        || die "No interactive terminal detected. Use --template=<name> or --modules=a,b,c (see --help)."

    ui_width
    ui_raw_on
    ui_anchor
    UI_LINES=0
    UI_ACTIVE=1
    printf '\033[?25l'
    cleanup_on_exit
}

ui_end() {
    (( UI_ACTIVE )) || { ui_raw_off; return 0; }

    UI_ACTIVE=0
    printf '\033[?25h\n'
    ui_raw_off
    return 0
}

ui_home() {
    if [[ -n "$UI_TOP" ]]; then
        printf '\033[%s;1H\033[J' "$UI_TOP"
    elif (( UI_LINES > 0 )); then
        printf '\033[%dA\r\033[J' "$UI_LINES"
    else
        printf '\r\033[J'
    fi

    return 0
}

# Every line of every frame goes through one of these, because UI_LINES is what
# the no-anchor fallback counts.
ui_line() {
    printf '%s\n' "$1"
    UI_LINES=$(( UI_LINES + 1 ))
}

ui_blank() {
    ui_line ''
}

ui_rule() {
    local bar
    printf -v bar '%*s' "$(( UI_WIDTH - 4 ))" ''
    printf '  %s%s%s\n' "$C_DIM" "${bar// /─}" "$C_RESET"
    UI_LINES=$(( UI_LINES + 1 ))
}

ui_heading() {
    ui_blank
    ui_line "  ${C_BOLD}$1${C_RESET} ${C_DIM}$2${C_RESET}"
}

# Turn one raw key into one named key. ESC is ambiguous — it is both "go back"
# and the start of every arrow key — so it is resolved by a short timeout: a byte
# that does not arrive within 50ms is a bare ESC, and a byte that does is the
# second half of a sequence.
ui_read_key() {
    local key rest seq

    if ! IFS= read -rsn1 key; then
        UI_KEY="EOF"
        return 1
    fi

    # `read -n1` assigns an *empty* string when the byte it read is a newline,
    # because a newline is bash's delimiter rather than data. Enter arrives as a
    # carriage return, ICRNL turns that into a newline, and the Enter keypress
    # therefore reaches this function as "" — which matches no case below, so the
    # menu silently ignores it and the user cannot confirm anything. Reading the
    # key as a newline when it came back empty is the whole fix.
    [[ -z "$key" ]] && key=$'\n'

    case "$key" in
        $'\e')
            if IFS= read -rsn1 -t 0.05 rest; then
                case "$rest" in
                    '[')
                        if IFS= read -rsn1 -t 0.05 rest; then
                            case "$rest" in
                                A) UI_KEY="UP" ;;
                                B) UI_KEY="DOWN" ;;
                                C) UI_KEY="RIGHT" ;;
                                D) UI_KEY="LEFT" ;;
                                H) UI_KEY="HOME" ;;
                                F) UI_KEY="END" ;;
                                [0-9]*)
                                    seq="$rest"
                                    while IFS= read -rsn1 -t 0.05 rest && [[ "$rest" != '~' ]]; do
                                        seq+="$rest"
                                    done
                                    case "$seq" in
                                        1|7) UI_KEY="HOME" ;;
                                        4|8) UI_KEY="END" ;;
                                        3)    UI_KEY="DELETE" ;;
                                        5)    UI_KEY="PAGEUP" ;;
                                        6)    UI_KEY="PAGEDOWN" ;;
                                        *)    UI_KEY="UNKNOWN" ;;
                                    esac
                                    ;;
                                *) UI_KEY="UNKNOWN" ;;
                            esac
                        else
                            UI_KEY="ESC"
                        fi
                        ;;
                    'O')
                        if IFS= read -rsn1 -t 0.05 rest; then
                            case "$rest" in
                                A) UI_KEY="UP" ;;
                                B) UI_KEY="DOWN" ;;
                                H) UI_KEY="HOME" ;;
                                F) UI_KEY="END" ;;
                                *) UI_KEY="UNKNOWN" ;;
                            esac
                        else
                            UI_KEY="ESC"
                        fi
                        ;;
                    *) UI_KEY="ESC" ;;
                esac
            else
                UI_KEY="ESC"
            fi
            ;;
        $'\n'|$'\r')  UI_KEY="ENTER" ;;
        ' ')           UI_KEY="SPACE" ;;
        $'\t')         UI_KEY="TAB" ;;
        $'\x7f'|$'\b') UI_KEY="BACKSPACE" ;;
        $'\x03')       UI_KEY="INTERRUPT" ;;
        *)             UI_KEY="$key" ;;
    esac

    return 0
}

# ---------------------------------------------------------------- interactive

TEMPLATE_CHOICE=''

# Screen 1. The template list is the first thing a new user can act on without
# knowing any module names, so it is the first screen; "Custom" is the escape
# hatch to the full list rather than a separate flag.
interactive_template_screen() {
    local -a entries=()
    local name idx cursor=0 last

    for name in ${TEMPLATE_NAMES[@]+"${TEMPLATE_NAMES[@]}"}; do
        entries+=("$name")
    done
    entries+=("$CUSTOM_TEMPLATE")

    last=$(( ${#entries[@]} - 1 ))

    while true; do
        ui_home

        ui_heading "Phue" "create a new project"
        ui_line "  ${C_DIM}${#entries[@]} templates · enter selects · esc and q quit${C_RESET}"
        ui_blank

        for (( idx = 0; idx <= last; idx++ )); do
            name="${entries[$idx]}"
            local pointer marker title description tags

            # Custom is drawn like every other row — same marker, same pointer,
            # same cursor highlight. It is a choice like the rest, and an entry
            # that looks different is an entry people read as a separator and
            # scroll straight past.
            if [[ "$name" == "$CUSTOM_TEMPLATE" ]]; then
                title="Custom"
                description="Pick the modules yourself, one by one"
                tags=""
            else
                title="${TEMPLATE_TITLE[$name]}"
                description="${TEMPLATE_DESC[$name]}"
                tags="${TEMPLATE_TAGS[$name]}"
            fi

            if (( idx == cursor )); then
                pointer="${C_CYAN}❯${C_RESET}"
                marker="${C_GREEN}●${C_RESET}"
                title="${C_BOLD}${title}${C_RESET}"
            else
                pointer="  "
                marker="${C_DIM}○${C_RESET}"
            fi

            ui_line "  ${pointer}${marker} ${title}"
            ui_line "       ${C_DIM}${description}${C_RESET}"

            if [[ -n "$tags" ]]; then
                ui_line "       ${C_DIM}${tags}${C_RESET}"
            fi
        done

        ui_blank
        ui_rule "↑/↓ move · enter select · q quit"

        ui_read_key || die "Cancelled."

        case "$UI_KEY" in
            UP)         cursor=$(( cursor == 0 ? last : cursor - 1 )) ;;
            DOWN)       cursor=$(( cursor == last ? 0 : cursor + 1 )) ;;
            HOME)       cursor=0 ;;
            END)        cursor=$last ;;
            PAGEUP)     cursor=0 ;;
            PAGEDOWN)   cursor=$last ;;
            INTERRUPT)  die "Cancelled." ;;
            q|Q)        die "Cancelled." ;;
            ENTER)
                TEMPLATE_CHOICE="${entries[$cursor]}"
                return 0
                ;;
        esac
    done
}

# Screen 2. Reached by choosing Custom, and reachable again from here with esc:
# a wrong turn in a two-screen wizard must not cost the user everything they
# already picked.
interactive_module_screen() {
    local -a all=() picked=()
    local id idx cursor=0 count last
    local -a flags=()

    while IFS= read -r id; do
        all+=("$id")
        flags+=(0)
    done < <(module_ids)

    count=${#all[@]}
    last=$(( count - 1 ))

    while true; do
        ui_home

        ui_heading "Modules" "pick what this project needs"
        ui_line "  ${C_DIM}${count} available · space toggles · a all · n none${C_RESET}"
        ui_blank

        picked=()
        for (( idx = 0; idx < count; idx++ )); do
            (( ${flags[$idx]} )) && picked+=("${all[$idx]}")

            local mid="${all[$idx]}"
            local pointer box label

            if (( idx == cursor )); then
                pointer="${C_CYAN}❯${C_RESET}"; label="${C_BOLD}${mid}${C_RESET}"
            else
                pointer="  "; label="${mid}"
            fi

            if (( ${flags[$idx]} )); then
                box="${C_GREEN}◼${C_RESET}"
            else
                box="${C_DIM}◻${C_RESET}"
            fi

            ui_line "  ${pointer}${box} ${label}"

            # The requirement is shown where the choice is made, not resolved
            # behind the user's back at the end: a module that cannot be
            # installed alone is a fact about the module, and hiding it until
            # after the summary is how a user ends up surprised by a config
            # file they have not read yet.
            local hint=""
            [[ -n "${MODULE_REQUIRES[$mid]:-}" ]] \
                && hint="${C_DIM}  needs ${MODULE_REQUIRES[$mid]}${C_RESET}"

            ui_line "       ${C_DIM}${MODULE_SUMMARY[$mid]}${C_RESET}${hint}"
        done

        # The preview resolves dependencies rather than showing the raw toggles,
        # because the raw toggles are not what will be installed: picking rest
        # without auth installs both, and a user who cannot see that before
        # pressing enter will only find out by reading config/app.php afterwards.
        local preview
        preview="$(order_ids "$(expand_dependencies "$(join_comma ${picked[@]+"${picked[@]}"})")")"

        ui_blank
        ui_line "  ${C_DIM}will install${C_RESET}  ${C_BOLD}$(describe_selection "$preview")${C_RESET}"
        ui_rule "space toggle · a all · n none · esc back · q quit"

        ui_read_key || die "Cancelled."

        case "$UI_KEY" in
            UP)        cursor=$(( cursor == 0 ? last : cursor - 1 )) ;;
            DOWN)      cursor=$(( cursor == last ? 0 : cursor + 1 )) ;;
            HOME)      cursor=0 ;;
            END)       cursor=$last ;;
            PAGEUP)    cursor=0 ;;
            PAGEDOWN)  cursor=$last ;;
            SPACE)     flags[$cursor]=$(( 1 - ${flags[$cursor]} )) ;;
            a|A)
                for (( idx = 0; idx < count; idx++ )); do flags[$idx]=1; done
                ;;
            n|N)
                for (( idx = 0; idx < count; idx++ )); do flags[$idx]=0; done
                ;;
            ESC|BACKSPACE) return 1 ;;
            INTERRUPT) die "Cancelled." ;;
            q|Q)       die "Cancelled." ;;
            ENTER)
                SELECTED_MODULES=()
                for (( idx = 0; idx < count; idx++ )); do
                    (( ${flags[$idx]} )) && SELECTED_MODULES+=("${all[$idx]}")
                done

                if (( ${#SELECTED_MODULES[@]} == 0 )); then
                    warn "Nothing selected — starting from the core framework only."
                fi

                return 0
                ;;
        esac
    done
}

interactive_select() {
    ui_begin

    while true; do
        interactive_template_screen

        if [[ "$TEMPLATE_CHOICE" != "$CUSTOM_TEMPLATE" ]]; then
            ui_end
            TEMPLATE_NAME="$TEMPLATE_CHOICE"
            TEMPLATE_DESC_USED="${TEMPLATE_DESC[$TEMPLATE_CHOICE]}"
            SELECTED_MODULES=()
            select_modules "$(template_selection "$TEMPLATE_CHOICE")"
            return 0
        fi

        if interactive_module_screen; then
            ui_end
            TEMPLATE_NAME="$CUSTOM_TEMPLATE"
            TEMPLATE_DESC_USED="modules picked by hand"
            return 0
        fi
    done
}

# ---------------------------------------------------------------- install plan
#
# Nothing is copied until the whole install has been described as a list of
# paths. --dry-run prints that list, the real run walks it, and both go through
# the same builder, so the preview cannot describe an install that differs from
# the one that happens.
#
# A source that is not in this framework becomes a skipped entry with a reason.
# The old behaviour printed a warning per missing file and carried on, which on a
# wide terminal is the same as printing nothing: the user ends up with a project
# missing a file and no record of which one.

declare -a PLAN=()
declare -a SKIPPED=()
PLAN_COUNT=0

plan_add() {
    local kind="$1" src="$2" dst="$3"

    if [[ ! -e "$REPO_ROOT/$src" ]]; then
        SKIPPED+=("$src|not in this framework")
        return 0
    fi

    PLAN+=("$kind|$src|$dst")
    return 0
}

plan_dir()  { plan_add dir  "$1" "$2"; }
plan_file() { plan_add file "$1" "$2"; }

run_plan() {
    local dry_run="${1:-0}"
    local entry kind src dst

    for entry in ${PLAN[@]+"${PLAN[@]}"}; do
        IFS='|' read -r kind src dst <<< "$entry"

        if (( dry_run )); then
            printf '  %s%-4s %s%s\n' "$C_DIM" "$kind" "$dst" "$C_RESET"
            PLAN_COUNT=$(( PLAN_COUNT + 1 ))
            continue
        fi

        case "$kind" in
            dir)
                mkdir -p "$TARGET/$dst"
                cp -a "$REPO_ROOT/$src/." "$TARGET/$dst/"
                ;;
            file)
                mkdir -p "$(dirname "$TARGET/$dst")"
                cp -a "$REPO_ROOT/$src" "$TARGET/$dst"
                ;;
        esac

        PLAN_COUNT=$(( PLAN_COUNT + 1 ))
    done

    local skipped
    for skipped in ${SKIPPED[@]+"${SKIPPED[@]}"}; do
        printf '  %s-%s %s %s(%s)%s\n' \
            "$C_YELLOW" "$C_RESET" "${skipped%%|*}" \
            "$C_DIM" "${skipped#*|}" "$C_RESET"
    done

    return 0
}

build_plan() {
    local id file req r missing

    # Framework core: not modules, and not configurable. A Phue app without these
    # is not a Phue app.
    plan_dir "bin" "bin"
    plan_dir "public" "public"
    plan_dir "config" "config"
    plan_dir "src/Core" "src/Core"
    plan_dir "docs" "docs"
    plan_dir "docker" "docker"
    plan_file "ssr/server.ts" "ssr/server.ts"
    plan_file "ssr/tsconfig.json" "ssr/tsconfig.json"
    plan_file "ssr/package.json" "ssr/package.json"
    plan_file "ssr/bun.lock" "ssr/bun.lock"
    plan_file "phue-docker" "phue-docker"
    plan_file "phue-packages.json" "phue-packages.json"
    plan_file ".env.example" ".env.example"
    plan_file ".gitignore" ".gitignore"

    # App skeleton.
    plan_dir "App/Errors" "App/Errors"
    plan_file "App/Frontend/AppLayout.phue" "App/Frontend/AppLayout.phue"
    plan_dir "App/Frontend/Pages" "App/Frontend/Pages"
    plan_file "App/Controllers/Controller.php" "App/Controllers/Controller.php"
    plan_file "App/Controllers/WebController.php" "App/Controllers/WebController.php"
    plan_file "App/Config/NavigationConfig.php" "App/Config/NavigationConfig.php"

    # Selected modules, and everything that travels with one.
    for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        plan_dir "${MODULE_DIR[$id]}" "${MODULE_DIR[$id]}"

        [[ -n "${MODULE_TESTS[$id]:-}" ]] && plan_dir "${MODULE_TESTS[$id]}" "${MODULE_TESTS[$id]}"
        [[ -n "${MODULE_CONFIG[$id]:-}" ]] && plan_file "${MODULE_CONFIG[$id]}" "${MODULE_CONFIG[$id]}"

        for file in ${MODULE_COMPANIONS[$id]:-}; do
            req="${COMPANION_REQUIRES[$file]:-}"
            missing=0

            split_csv "$req"

            for r in ${SPLIT[@]+"${SPLIT[@]}"}; do
                # Resolved rather than compared: a companion requirement is
                # written the way a person would type it, and the modules that
                # matter most here (auth) are exactly the ones whose id is not
                # the lowercase spelling.
                is_selected "$(resolve_module_id "$r" || printf '%s' "$r")" || missing=1
            done

            if (( missing )); then
                SKIPPED+=("$file|needs ${req//,/, }")
                continue
            fi

            plan_file "$file" "$file"
        done
    done

    # Tooling directories ride along with every project: they are part of how
    # the framework is worked with, not something a project opts into.
    for id in $(tooling_ids); do
        plan_dir "${MODULE_DIR[$id]}" "${MODULE_DIR[$id]}"
        [[ -n "${MODULE_TESTS[$id]:-}" ]] && plan_dir "${MODULE_TESTS[$id]}" "${MODULE_TESTS[$id]}"
    done

    plan_dir "tests/Support" "tests/Support"
    return 0
}

# ---------------------------------------------------------------- generators

gen_config_app() {
    local target="$1" entries="" id devtool="false"

    for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        is_selected devtools && devtool="true"
        entries+="        \\${MODULE_CLASS[$id]}::class,"$'\n'
    done

    [[ -n "$entries" ]] \
        || entries="        // No modules yet. Add one with: php phue module:new <name>"$'\n'

    cat > "$target/config/app.php" <<EOF
<?php

declare(strict_types=1);

use function Phue\\Core\\env;

return [
    'name'    => env('APP_NAME', 'Phue App'),
    'env'     => env('APP_ENV', 'development'),
    'debug'   => env('APP_DEBUG', true),
    'devTool' => env('APP_DEV_TOOL', ${devtool}),
    'appKey'  => env('APP_KEY'),
    'trustedProxies' => [],
    'modules' => [
${entries}    ],
];
EOF
}

gen_phpunit() {
    local target="$1" suites="" id

    for id in ${MODULE_IDS[@]+"${MODULE_IDS[@]}"}; do
        if [[ "${MODULE_KIND[$id]}" != "tooling" ]] && ! is_selected "$id"; then
            continue
        fi

        [[ -n "${MODULE_TESTS[$id]:-}" ]] || continue

        suites+="        <testsuite name=\"${id}\">"$'\n'
        suites+="            <directory suffix=\"Test.php\">${MODULE_TESTS[$id]}</directory>"$'\n'
        suites+="        </testsuite>"$'\n'
    done

    suites+="        <testsuite name=\"app\">"$'\n'
    suites+="            <directory suffix=\"Test.php\">tests/App</directory>"$'\n'
    suites+="        </testsuite>"$'\n'

    cat > "$target/phpunit.xml" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
${suites}    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
EOF
}

gen_composer() {
    local target="$1" base="${PROJECT_DIR##*/}"
    local pkg_name suggest="" ext id

    pkg_name="$(printf '%s' "$base" | tr '[:upper:] ' '[:lower:]-')"

    for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        for ext in ${MODULE_SUGGEST[$id]:-}; do
            case "$ext" in
                ext-redis|ext-swoole)
                    [[ -n "$suggest" ]] && suggest+=$'\n'
                    suggest+="        \"${ext}\": \"used by the ${id} module\","
                    ;;
            esac
        done
    done

    # One trailing comma, removed once at the end. Appended per entry it becomes
    # a trailing comma on every line, and composer --validate then rejects the
    # file for a reason that reads like a bug in the project rather than in the
    # installer that wrote it.
    suggest="${suggest%,}"

    cat > "$target/composer.json" <<EOF
{
    "name": "${pkg_name}",
    "description": "A Phue application.",
    "type": "project",
    "license": "MIT",
    "scripts": {
        "test": "phpunit",
        "phue": "php phue"
    },
    "require": {
        "php": ">=8.5",
        "nyholm/psr7": "^1.8",
        "psr/simple-cache": "^3.0",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "suggest": {
${suggest}    },
    "autoload": {
        "psr-4": {
            "Phue\\\\": "src/",
            "App\\\\": "App/"
        },
        "files": [
            "src/Core/functions.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "Phue\\\\Tests\\\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
EOF
}

gen_storage() {
    local target="$1" sdir

    for sdir in assets cache generated logs; do
        mkdir -p "$target/storage/$sdir"
        touch "$target/storage/$sdir/.gitkeep"
    done

    mkdir -p "$target/tests/App"
    touch "$target/tests/App/.gitkeep"
    return 0
}

# ---------------------------------------------------------------- main

cleanup() {
    # Restores the terminal before anything else: this also runs on SIGINT, and
    # a shell left with echo off and no line buffering is a shell the user has
    # to fix by hand.
    ui_end
    [[ -n "$CLONE_PARENT" && -d "$CLONE_PARENT" ]] && rm -rf "$CLONE_PARENT"
    return 0
}

cleanup_on_exit() {
    trap 'cleanup' EXIT
    trap 'cleanup; exit 130' INT
}

PROJECT_DIR=""
TARGET=""
REPO_ROOT=""
CLONE_PARENT=""
ARG_TEMPLATE=""
ARG_MODULES=""
ARG_REPO=""
ARG_FORCE=0
ARG_LIST_TEMPLATES=0
ARG_LIST_MODULES=0
ARG_DRY_RUN=0

cleanup_on_exit

while [[ $# -gt 0 ]]; do
    case "$1" in
        --template=*)           ARG_TEMPLATE="${1#*=}" ;;
        --modules=*)            ARG_MODULES="${1#*=}" ;;
        --repo=*)               ARG_REPO="${1#*=}" ;;
        --list-templates|--list) ARG_LIST_TEMPLATES=1 ;;
        --list-modules)         ARG_LIST_MODULES=1 ;;
        --dry-run)              ARG_DRY_RUN=1 ;;
        --force)                ARG_FORCE=1 ;;
        -h|--help)              usage; exit 0 ;;
        -*)  die "Unknown option '$1'." "Run --help for the options this installer takes." ;;
        *)
            if [[ -z "$PROJECT_DIR" ]]; then
                PROJECT_DIR="$1"
            else
                die "Unexpected extra argument '$1'."
            fi
            ;;
    esac
    shift
done

need php "php is required to run the installer (and Phue itself)."

[[ -n "$ARG_TEMPLATE" && -n "$ARG_MODULES" ]] \
    && die "Choose either --template=<name> or --modules=a,b,c, not both."

# Resolve the framework source: this repo, --repo=<path>, or clone from URL.
if [[ -n "$ARG_REPO" ]]; then
    REPO_ROOT="$ARG_REPO"
else
    REPO_ROOT="$(cd "$SCRIPT_DIR/../.." 2>/dev/null && pwd || true)"
    [[ -f "$REPO_ROOT/composer.json" && -d "$REPO_ROOT/src/Core" ]] || REPO_ROOT=""
fi

if [[ -z "$REPO_ROOT" ]]; then
    PHUE_REPO_URL="${PHUE_REPO_URL:-https://github.com/MakariosVar/phue.git}"
    note "Phue source not found locally — cloning ${PHUE_REPO_URL} ..."
    need git "git is required to download the Phue framework."
    CLONE_PARENT="$(mktemp -d)"
    REPO_ROOT="$CLONE_PARENT/phue"
    git clone --depth 1 "$PHUE_REPO_URL" "$REPO_ROOT"
fi

if [[ ! -f "$REPO_ROOT/composer.json" || ! -d "$REPO_ROOT/src/Core" ]]; then
    die "'$REPO_ROOT' does not look like a Phue framework source." "Point at one with --repo=<path>."
fi

TEMPLATES_DIR="$REPO_ROOT/tools/installer/templates"
[[ -d "$TEMPLATES_DIR" ]] || TEMPLATES_DIR="$SCRIPT_DIR/templates"
[[ -d "$TEMPLATES_DIR" ]] || die "No templates found. Expected them in tools/installer/templates."

load_modules
load_templates

if (( ARG_LIST_TEMPLATES )); then
    list_templates
    exit 0
fi

if (( ARG_LIST_MODULES )); then
    list_modules
    exit 0
fi

# Resolve the module selection.
SELECTED_MODULES=()

if [[ -n "$ARG_TEMPLATE" ]]; then
    TEMPLATE_KEY="${ARG_TEMPLATE,,}"

    if [[ -z "${TEMPLATE_MODULES[$TEMPLATE_KEY]+set}" ]]; then
        die "Unknown template '$ARG_TEMPLATE'." "Run --list-templates to see: ${TEMPLATE_NAMES[*]}"
    fi

    TEMPLATE_NAME="$TEMPLATE_KEY"
    TEMPLATE_DESC_USED="${TEMPLATE_DESC[$TEMPLATE_KEY]}"
    select_modules "$(template_selection "$TEMPLATE_KEY")"
elif [[ -n "$ARG_MODULES" ]]; then
    # An explicit --modules with nothing after it is a typo, not a request for
    # the core-only install that the blank template already spells.
    [[ -n "${ARG_MODULES//[[:space:]]/}" ]] \
        || die "--modules was given with no module ids." "Try --list-modules to see the options."

    select_modules "$ARG_MODULES"
else
    interactive_select
fi

resolve_dependencies
normalize_selection_order

# Resolve the target project directory.
if [[ -z "$PROJECT_DIR" ]]; then
    if [[ -t 0 ]]; then
        read -rp "  ${C_BOLD}Project name:${C_NC} " PROJECT_DIR
        [[ -n "$PROJECT_DIR" ]] || die "Project name is required."
    else
        die "Missing project directory argument." "Usage: phue-create.sh <project-dir> [options]"
    fi
fi

[[ "$PROJECT_DIR" =~ ^[A-Za-z0-9._/-]+$ ]] \
    || die "Invalid project directory name '$PROJECT_DIR'."

if [[ -e "$PROJECT_DIR" ]]; then
    if [[ -d "$PROJECT_DIR" && -z "$(ls -A "$PROJECT_DIR" 2>/dev/null)" ]]; then
        : # empty directory — reuse it
    elif (( ! ARG_FORCE )); then
        die "'$PROJECT_DIR' already exists and is not empty." "Use --force to continue anyway."
    fi
fi

build_plan

if (( ARG_DRY_RUN )); then
    printf '\n  %sDry run%s — nothing below is written.\n\n' "$C_BOLD" "$C_RESET"
    printf '  %sTarget%s   %s\n' "$C_DIM" "$C_RESET" "${PROJECT_DIR:-<from prompt>}"
    printf '  %sTemplate%s %s — %s\n' "$C_DIM" "$C_RESET" "$TEMPLATE_NAME" "$TEMPLATE_DESC_USED"
    printf '  %sModules%s  %s\n\n' "$C_DIM" "$C_RESET" \
        "$(describe_selection "$(join_comma ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"})")"
    run_plan 1
    printf '\n'
    exit 0
fi

mkdir -p "$PROJECT_DIR"
TARGET="$(cd "$PROJECT_DIR" && pwd)"

printf '\n'
printf '  %s◆%s  Creating Phue project at %s\n' "$C_BOLD" "$C_RESET" "$TARGET"
printf '  %s◆%s  %s — %s\n' "$C_BOLD" "$C_RESET" "$TEMPLATE_NAME" "$TEMPLATE_DESC_USED"
printf '\n'

run_plan 0

ln -s bin/phue "$TARGET/phue" 2>/dev/null || true
chmod +x "$TARGET/phue" 2>/dev/null || true
chmod +x "$TARGET/phue-docker" 2>/dev/null || true

gen_storage "$TARGET"
gen_config_app "$TARGET"
gen_phpunit "$TARGET"
gen_composer "$TARGET"
cp "$TARGET/.env.example" "$TARGET/.env"

printf '\n'
ok "Created in $TARGET — $PLAN_COUNT entries, $(describe_selection "$(join_comma ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"})")."

if (( ${#SELECTED_MODULES[@]} > 0 )); then
    printf '\n  %sModules%s\n' "$C_BOLD" "$C_RESET"
    for id in "${SELECTED_MODULES[@]}"; do
        printf '    %s%-12s%s %s\n' "$C_GREEN" "$id" "$C_RESET" "${MODULE_SUMMARY[$id]}"
    done
fi

printf '\n  %sNext steps%s\n' "$C_BOLD" "$C_RESET"
printf '    cd %s\n' "$TARGET"
printf '    composer install\n'
printf '    ./phue serve\n'
printf '\n'
