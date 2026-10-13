#!/usr/bin/env bash
#
# Phue-create installer test suite.
#
# The installer is the one piece of this framework that runs before anything
# else exists: no vendor/, no autoloader, a source checkout that may have no
# dependencies installed, and a terminal that may or may not be there. Tests
# that assume any of those are testing a different program, so every test here
# runs the real script the way a user runs it and asserts on the tree it leaves.
#
# Three kinds of test:
#
#   1. Behaviour — run the installer, look at the project it produced.
#   2. Discovery — the module scan is the contract, so it is checked against the
#      framework's own src/Modules rather than against a list written here. A
#      list in the test suite would drift exactly the way the installer's did.
#   3. Interaction — the menu is a terminal program, so it is driven through a
#      pty and judged on the screen a user would see, not on the bytes it wrote.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER="$SCRIPT_DIR/../phue-create.sh"
FRAMEWORK="${FRAMEWORK_ROOT:-$(cd "$SCRIPT_DIR/../../.." && pwd)}"
PTY="$SCRIPT_DIR/pty_run.py"

if [[ -t 1 ]]; then
    C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_CYAN=$'\033[36m'
    C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_NC=$'\033[0m'
else
    C_GREEN=''; C_RED=''; C_CYAN=''; C_YELLOW=''; C_BOLD=''; C_NC=''
fi

TOTAL=0
PASS=0
CURRENT_FAILURES=0
FILTER="${1:-}"

TEST_TMP=""
TARGET=""
INSTALLER_OUT=""
INSTALLER_EXIT=0

# ---------------------------------------------------------------- helpers

setup() {
    TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/phue-create-test.XXXXXX")"
    TARGET="$TEST_TMP/my-app"
}

teardown() {
    [[ -n "$TEST_TMP" && -d "$TEST_TMP" ]] && rm -rf "$TEST_TMP"
    TEST_TMP=""
    TARGET=""
}

fail() {
    CURRENT_FAILURES=$(( CURRENT_FAILURES + 1 ))
    printf '    %s✗%s %s\n' "$C_RED" "$C_NC" "$1"
}

# ---------------------------------------------------------------- assertions

assert_exit() {
    local expected="$1" actual="$2"
    (( expected == actual )) || fail "exit code: expected $expected, got $actual"
}

assert_file()      { [[ -f "$1" ]] || fail "file not found: ${1#$TEST_TMP/}"; }
assert_no_file()   { [[ ! -e "$1" ]] || fail "file unexpectedly exists: ${1#$TEST_TMP/}"; }
assert_dir()       { [[ -d "$1" ]] || fail "directory not found: ${1#$TEST_TMP/}"; }
assert_no_dir()    { [[ ! -d "$1" ]] || fail "directory unexpectedly exists: ${1#$TEST_TMP/}"; }

assert_contains() {
    grep -qF -- "$2" "$1" 2>/dev/null || fail "'$2' not found in ${1#$TEST_TMP/}"
}

assert_not_contains() {
    grep -qF -- "$2" "$1" 2>/dev/null && fail "'$2' unexpectedly found in ${1#$TEST_TMP/}"
    return 0
}

assert_output_contains() {
    grep -qF -- "$1" <<< "$INSTALLER_OUT" || fail "output does not contain: '$1'"
}

assert_output_lacks() {
    grep -qF -- "$1" <<< "$INSTALLER_OUT" && fail "output unexpectedly contains: '$1'"
    return 0
}

assert_valid_php() {
    php -l "$1" >/dev/null 2>&1 || fail "invalid PHP: ${1#$TEST_TMP/}"
}

assert_valid_json() {
    php -r 'exit(is_array(json_decode(file_get_contents($argv[1]), true)) ? 0 : 1);' "$1" \
        || fail "invalid JSON: ${1#$TEST_TMP/}"
}

assert_count() {
    local expected="$1" actual="$2" what="$3"
    (( expected == actual )) || fail "$what: expected $expected, got $actual"
}

# ---------------------------------------------------------------- runners

run_installer() {
    local out
    out="$(bash "$INSTALLER" "$@" </dev/null 2>&1)"
    INSTALLER_EXIT=$?
    INSTALLER_OUT="$out"
}

# Drives the interactive installer through a pty and leaves the visible screen
# in SCREEN. The screen, not the byte stream: a full-screen menu rewrites itself
# on every keypress, so the bytes always contain one copy per keystroke and
# counting them says nothing about what the user ended up looking at.
run_interactive() {
    local keys="$1"
    shift

    SCREEN="$(python3 "$PTY" --screen --keys "$keys" --marker "$1" \
        bash "$INSTALLER" "${@:2}" 2>/dev/null)"
    INSTALLER_OUT="$SCREEN"
    INSTALLER_EXIT=0
}

SCREEN=""
MENU_ROWS=""

run_test() {
    local name="$1"
    shift

    if [[ -n "$FILTER" && "$name" != *"$FILTER"* ]]; then
        return 0
    fi

    TOTAL=$(( TOTAL + 1 ))
    CURRENT_FAILURES=0
    printf '%s▶%s %s\n' "$C_CYAN" "$C_NC" "$name"

    setup
    "$@"
    teardown

    if (( CURRENT_FAILURES == 0 )); then
        PASS=$(( PASS + 1 ))
        printf '    %s✓ passed%s\n\n' "$C_GREEN" "$C_NC"
    else
        printf '    %s✗ FAILED%s\n\n' "$C_RED" "$C_NC"
    fi
}

# ---------------------------------------------------------------- helpers

# Every module the framework actually ships, read from the framework itself
# rather than from a list maintained next to the installer.
FRAMEWORK_PROBE="$SCRIPT_DIR/framework_probe.php"

framework_modules() {
    php "$FRAMEWORK_PROBE" "$FRAMEWORK/src/Modules"
}

framework_module_ids() {
    framework_modules | cut -f1
}

# <field> <id> — field is one of id, dir, class
framework_module_field() {
    framework_modules | awk -F'\t' -v id="$2" -v field="$1" '
        $1 == id {
            if (field == "id")    { print $1 }
            else if (field == "dir")   { print $2 }
            else if (field == "class") { print $3 }
        }'
}

module_count() {
    framework_module_ids | wc -l | tr -d ' '
}

# <id> — how many downs it takes to reach a module on the interactive checklist.
#
# The checklist is the framework's own directory order, so a new module shifts
# every row below it. Deriving the count here keeps the keyboard tests honest
# about what they are testing: the keys, not the position of rest in a list.
module_row() {
    framework_modules | cut -f1 | grep -n "^$1$" | cut -d: -f1
}

# <id>... — a down-arrow sequence that walks to the given rows.
downs_to() {
    local sequence="" target index row

    for target in "$@"; do
        index="$(module_row "$target")"
        [[ -n "$index" ]] || continue

        # Row 1 is the first module and the cursor starts there, so reaching row
        # N is N-1 presses — not N, and not one per argument.
        for (( row = 1; row < index; row++ )); do
            sequence+='\e[B,'
        done
    done

    printf '%s' "$sequence"
}

# ---------------------------------------------------------------- cli surface

t_help_exits_zero() {
    run_installer --help
    assert_exit 0 "$INSTALLER_EXIT"
    assert_output_contains "Usage: phue-create.sh"
    assert_output_contains "--list-templates"
    assert_output_contains "--list-modules"
    assert_output_contains "--dry-run"
}

t_list_templates_shows_every_template() {
    run_installer --list-templates
    assert_exit 0 "$INSTALLER_EXIT"

    local f
    for f in "$FRAMEWORK"/tools/installer/templates/*.json; do
        assert_output_contains "$(basename "$f" .json)"
    done
}

t_list_templates_describes_each_one() {
    run_installer --list-templates
    assert_exit 0 "$INSTALLER_EXIT"

    # A template with no description is a title in a list, which tells a user
    # nothing about when to pick it.
    local f name
    for f in "$FRAMEWORK"/tools/installer/templates/*.json; do
        name="$(basename "$f" .json)"
        local description
        description="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo trim($d["description"] ?? "");' "$f")"
        [[ -n "$description" ]] || fail "template '$name' has no description"
    done
}

t_list_modules_finds_every_module_directory() {
    run_installer --list-modules
    assert_exit 0 "$INSTALLER_EXIT"

    # The whole point of the scan: a module that exists in src/Modules but not
    # in the installer's list is a module nobody can install.
    local id
    while IFS= read -r id; do
        assert_output_contains "$id"
    done < <(framework_module_ids)
}

t_list_modules_reports_tooling() {
    run_installer --list-modules
    assert_output_contains "Phii"
    assert_output_contains "tooling"
}

t_list_modules_describes_every_module() {
    run_installer --list-modules
    assert_exit 0 "$INSTALLER_EXIT"

    # Guards the fallback chain: docblock summary, then the written override,
    # then the humanised id. A module that falls through all three still gets a
    # line, but it gets "Auth module", which is not a description.
    local id
    while IFS= read -r id; do
        grep -qE "^  +${id} +[a-zA-Z]+ +.{12,}" <<< "$INSTALLER_OUT" \
            || fail "module '$id' has no real description in --list-modules"
    done < <(framework_module_ids)
}

# ---------------------------------------------------------------- discovery

t_modules_flag_installs_a_module_the_old_list_never_had() {
    # queue, websocket, http-signing and rateLimit are all installable today and
    # none of them appeared in the installer's original hand-written list. The
    # directory and the class name come from the framework, not from the id:
    # rateLimit lives in src/Modules/RateLimit and is RateLimitModule, and a test
    # that derived them would pass whatever the installer happened to produce.
    local id dir class
    for id in queue websocket http-signing rateLimit; do
        dir="$(framework_module_field dir "$id")"
        class="$(framework_module_field class "$id")"

        if [[ -z "$dir" || -z "$class" ]]; then
            fail "framework has no module with id '$id'"
            continue
        fi

        rm -rf "$TARGET"
        run_installer "$TARGET" --modules="$id"

        assert_exit 0 "$INSTALLER_EXIT"
        assert_dir "$TARGET/src/Modules/$dir"
        assert_contains "$TARGET/config/app.php" "Modules\\$dir\\$class::class"
    done
}

t_module_ids_are_tolerantly_spelled() {
    run_installer "$TARGET" --modules=ratelimit,rate_limit,httpsigning
    assert_exit 0 "$INSTALLER_EXIT"

    # rateLimit is the id the class declares. Someone typing it lowercase, or
    # with a hyphen, means the same module.
    assert_contains "$TARGET/config/app.php" "RateLimitModule"
    assert_contains "$TARGET/config/app.php" "HttpSigningModule"
}

t_fullstack_template_tracks_new_modules() {
    run_installer "$TARGET" --template=fullstack
    assert_exit 0 "$INSTALLER_EXIT"

    # "*" rather than a written list: this test fails the day a module is added
    # to the framework and the template is not, which is the entire failure mode
    # a hand-written list has. Directory and class name are read from the
    # framework, because an id is neither of them.
    local id dir class
    while IFS=$'\t' read -r id dir class; do
        assert_dir "$TARGET/src/Modules/$dir"
        assert_contains "$TARGET/config/app.php" "Modules\\$dir\\$class::class"
    done < <(framework_modules)
}

t_scan_finds_tests_wherever_they_live() {
    # DevTool vs tests/DevTools is a spelling difference; tests/Modules/RateLimit
    # is a different layout entirely. A lookup table would have to be corrected
    # by hand for both.
    run_installer "$TARGET" --modules=devtools,rateLimit,http-signing
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/tests/DevTools"
    assert_dir "$TARGET/tests/Modules/RateLimit"
    assert_dir "$TARGET/tests/Modules/HttpSigning"
    assert_contains "$TARGET/phpunit.xml" 'name="devtools"'
    assert_contains "$TARGET/phpunit.xml" 'name="rateLimit"'
    assert_contains "$TARGET/phpunit.xml" 'name="http-signing"'
}

t_scan_finds_config_whatever_it_is_named() {
    run_installer "$TARGET" --modules=rateLimit,devtools,http-signing
    assert_exit 0 "$INSTALLER_EXIT"

    # rateLimit → rate-limit.php, devtools → devtool.php, http-signing → itself.
    assert_file "$TARGET/config/rate-limit.php"
    assert_file "$TARGET/config/devtool.php"
    assert_file "$TARGET/config/http-signing.php"
}

t_module_without_tests_gets_no_emptied_testsuite() {
    run_installer "$TARGET" --template=fullstack
    assert_exit 0 "$INSTALLER_EXIT"

    # An empty tests/Modules/Rest directory exists in the framework. A <testsuite>
    # pointing at it runs nothing while looking like coverage, so the installer
    # must skip it rather than copy the directory.
    local suite
    while IFS= read -r suite; do
        local dir="${suite#*>}"
        dir="${dir%%<*}"
        [[ -d "$FRAMEWORK/$dir" ]] || continue
        local found
        found="$(find "$FRAMEWORK/$dir" -name '*Test.php' | head -1)"
        if [[ -z "$found" ]]; then
            assert_not_contains "$TARGET/phpunit.xml" "<directory suffix=\"Test.php\">$dir</directory>"
        fi
    done < <(grep -o '<directory suffix="Test.php">[^<]*</directory>' "$FRAMEWORK/phpunit.xml")
}

# ---------------------------------------------------------------- companions

t_auth_companion_is_gated_on_its_requirement() {
    # AuthController annotates a route with #[RateLimit]. Copied into a project
    # without the RateLimit module, it is a controller that dies the first time
    # anything reflects on it.
    run_installer "$TARGET" --modules=auth
    assert_exit 0 "$INSTALLER_EXIT"

    assert_file "$TARGET/App/Models/User.php"
    assert_no_file "$TARGET/App/Controllers/AuthController.php"
    assert_output_contains "needs ratelimit"

    rm -rf "$TARGET"

    run_installer "$TARGET" --modules=auth,rateLimit
    assert_exit 0 "$INSTALLER_EXIT"
    assert_file "$TARGET/App/Controllers/AuthController.php"
}

t_scaffolded_app_never_references_an_uninstalled_module() {
    # A scaffolded App/ file that names a module class from a module nobody
    # installed is a project that boots into a fatal, or that silently ignores a
    # route attribute. Checked over the whole tree, for the eager references
    # only: attributes, `new`, `extends` and `implements`, all of which PHP
    # resolves when the class is first touched.
    #
    # Type hints are deliberately not checked. NavigationConfig declares
    # `?IdentityInterface` and is shipped with every template; PHP stores a
    # parameter type as a string and only resolves it when a non-null value is
    # passed, so a project without Auth never asks for it and the file is fine.
    # Demanding Auth for that would be the test being wrong rather than strict.
    local analyzer="$TEST_TMP/analyze.php"
    cat > "$analyzer" <<'PHP'
<?php

declare(strict_types=1);

$appRoot = $argv[1];
$modulesRoot = $argv[2];

$installed = [];

foreach (scandir($modulesRoot) ?: [] as $entry) {
    if ($entry !== '.' && $entry !== '..' && is_dir($modulesRoot . '/' . $entry)) {
        $installed[$entry] = true;
    }
}

$offenders = [];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }

    if (!in_array($file->getExtension(), ['php', 'phue'], true)) {
        continue;
    }

    $code = (string) file_get_contents($file->getPathname());
    $relative = str_replace($appRoot . '/', '', $file->getPathname());

    // Short name of every module class the file imports.
    $imports = [];

    if (preg_match_all('~^use\s+Phue\\\\Modules\\\\(\w+)\\\\([\w\\]+);~m', $code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $imports[substr($match[2], strrpos($match[2], '\\') + 1)] = $match[1];
        }
    }

    // Attributes are instantiated reflectively when a route is registered, so a
    // missing attribute class is a fatal at boot rather than a warning.
    $patterns = [
        'attribute' => '~#\[\s*\\?([A-Za-z_]\w*)~',
        'extends' => '~extends\s+\\?([A-Za-z_]\w*)~',
        'implements' => '~implements\s+\\?([A-Za-z_]\w*)~',
        'new' => '~new\s+\\?([A-Za-z_]\w*)~',
    ];

    foreach ($patterns as $kind => $pattern) {
        if (preg_match_all($pattern, $code, $found)) {
            foreach ($found[1] as $name) {
                if (!isset($imports[$name])) {
                    continue;
                }

                if (!isset($installed[$imports[$name]])) {
                    $offenders[] = sprintf('%s %ss %s (module %s)', $relative, $kind, $name, $imports[$name]);
                }
            }
        }
    }

    // A fully qualified reference is just as fatal, and needs no import.
    if (preg_match_all('~(?:new|#\[)\s*\\?Phue\\\\Modules\\\\(\w+)~', $code, $found)) {
        foreach ($found[1] as $module) {
            if (!isset($installed[$module])) {
                $offenders[] = sprintf('%s references module %s directly', $relative, $module);
            }
        }
    }
}

if ($offenders !== []) {
    fwrite(STDERR, implode("\n", array_unique($offenders)) . "\n");
    exit(1);
}

exit(0);
PHP

    local module
    for module in blank web api; do
        rm -rf "$TARGET"
        run_installer "$TARGET" --template="$module"
        assert_exit 0 "$INSTALLER_EXIT"

        php "$analyzer" "$TARGET/App" "$TARGET/src/Modules"             || fail "template '$module' scaffolds a file referencing a module it did not install"
    done
}

# ---------------------------------------------------------------- generated files

t_config_app_lists_installed_modules_and_nothing_else() {
    run_installer "$TARGET" --template=api
    assert_exit 0 "$INSTALLER_EXIT"

    assert_valid_php "$TARGET/config/app.php"
    assert_contains "$TARGET/config/app.php" "\Phue\Modules\Auth\AuthModule::class"
    assert_contains "$TARGET/config/app.php" "\Phue\Modules\Rest\RestModule::class"
    assert_not_contains "$TARGET/config/app.php" "\Phue\Modules\Websocket\WebsocketModule::class"

    # A doubled namespace compiles as a string but resolves to nothing at boot.
    assert_not_contains "$TARGET/config/app.php" "Phue\Modules\Phue"
}

t_config_app_without_modules_says_so() {
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    assert_valid_php "$TARGET/config/app.php"
    assert_contains "$TARGET/config/app.php" "No modules yet"
}

t_phpunit_suites_match_the_installed_modules() {
    run_installer "$TARGET" --template=api
    assert_exit 0 "$INSTALLER_EXIT"

    assert_contains "$TARGET/phpunit.xml" 'name="auth"'
    assert_contains "$TARGET/phpunit.xml" 'name="rest"'
    assert_contains "$TARGET/phpunit.xml" 'name="app"'
    assert_not_contains "$TARGET/phpunit.xml" 'name="websocket"'

    # Every suite directory has to exist, or PHPUnit fails on a path rather than
    # on a test.
    local dir
    while IFS= read -r dir; do
        [[ -d "$TARGET/$dir" ]] || fail "phpunit.xml claims $dir, which was not created"
    done < <(grep -o '<directory suffix="Test.php">[^<]*</directory>' "$TARGET/phpunit.xml" \
        | sed -E 's#.*>([^<]*)<.*#\1#')
}

t_composer_json_is_valid_and_suggests_what_is_needed() {
    run_installer "$TARGET" --template=fullstack
    assert_exit 0 "$INSTALLER_EXIT"

    assert_valid_json "$TARGET/composer.json"

    # Discovered from the modules' own source: a module that calls Redis should
    # not be discovered by a human the first time composer complains.
    assert_contains "$TARGET/composer.json" "ext-redis"
    assert_contains "$TARGET/composer.json" "ext-swoole"
    assert_contains "$TARGET/composer.json" '"Phue\\": "src/"'
}

t_composer_json_without_ext_modules_has_no_suggestions() {
    rm -rf "$TARGET"
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    assert_valid_json "$TARGET/composer.json"
    assert_not_contains "$TARGET/composer.json" "ext-redis"
}

t_generated_files_are_stable_between_runs() {
    # Same project name in a different parent: composer.json is named after the
    # directory, so comparing my-app against other-app would compare the name
    # field and prove nothing about the rest.
    local other="$TEST_TMP/other/my-app"

    run_installer "$TARGET" --template=api
    run_installer "$other" --template=api

    # Two installs of the same set must produce identical files, or a project
    # regenerated next month shows up as a diff in review.
    local file
    for file in config/app.php phpunit.xml composer.json; do
        cmp -s "$TARGET/$file" "$other/$file" || fail "$file differs between two identical installs"
    done
}

# ---------------------------------------------------------------- install plan

t_dry_run_writes_nothing() {
    run_installer "$TARGET" --template=api --dry-run
    assert_exit 0 "$INSTALLER_EXIT"

    assert_no_dir "$TARGET"
    assert_output_contains "Dry run"
    assert_output_contains "src/Modules/Auth"
}

t_dry_run_matches_the_real_install() {
    local other="$TEST_TMP/other-app"

    run_installer "$TARGET" --template=web --dry-run
    local planned
    planned="$(grep -E '^  (dir|file) ' <<< "$INSTALLER_OUT" \
        | grep -oE '(src|App|tests|config)/[^ ]+' | sort -u)"

    rm -rf "$TARGET"
    run_installer "$TARGET" --template=web
    assert_exit 0 "$INSTALLER_EXIT"

    # The preview is only worth reading if it describes the install that
    # happens, so it is compared against what was actually written.
    local entry missing=0
    while IFS= read -r entry; do
        [[ -e "$TARGET/$entry" ]] || { missing=1; fail "dry run listed '$entry', which was not created"; }
    done <<< "$planned"

    (( missing == 0 )) || true
}

t_dependency_resolution_reports_why() {
    run_installer "$TARGET" --modules=rest
    assert_exit 0 "$INSTALLER_EXIT"

    assert_contains "$TARGET/config/app.php" "AuthModule"
    assert_output_contains "added automatically"
    assert_output_contains "rest"
}

t_missing_framework_source_fails_clearly() {
    run_installer "$TARGET" --template=web --repo="$TEST_TMP/not-a-framework"
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "does not look like a Phue framework source"
}

t_template_naming_an_unknown_module_is_rejected() {
    # A broken template must fail when it is read, not halfway through writing
    # a project that quietly does not have the module it promised.
    local fake="$TEST_TMP/fake-repo"
    mkdir -p "$fake/src/Modules/Fake" "$fake/tools/installer/templates" "$fake/src/Core"

    echo '{"name":"fake"}' > "$fake/composer.json"
    echo '<?php' > "$fake/src/Core/Application.php"
    cat > "$fake/src/Modules/Fake/FakeModule.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Phue\Modules\Fake;

use Phue\Core\Module;

class FakeModule extends Module
{
    public string $id = 'fake';

    public function init(): void {}
}
PHP
    cat > "$fake/tools/installer/templates/broken.json" <<'JSON'
{
    "name": "broken",
    "order": 10,
    "title": "Broken",
    "description": "Names a module this framework does not have.",
    "modules": ["nonexistent"]
}
JSON

    run_installer "$TARGET" --template=broken --repo="$fake"
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "nonexistent"
    assert_no_dir "$TARGET/src"
}

# ---------------------------------------------------------------- failures

t_invalid_template_fails() {
    run_installer "$TARGET" --template=bogus
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "Unknown template"
    assert_no_dir "$TARGET/src"
}

t_invalid_module_fails_with_the_available_ones() {
    run_installer "$TARGET" --modules=bogus
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "Unknown module"
    assert_output_contains "auth"
    assert_no_dir "$TARGET/src"
}

t_template_and_modules_together_are_rejected() {
    run_installer "$TARGET" --template=web --modules=auth
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "not both"
}

t_unknown_option_fails() {
    run_installer "$TARGET" --nonsense
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "Unknown option"
}

t_existing_nonempty_dir_fails() {
    mkdir -p "$TARGET"
    touch "$TARGET/keep.txt"
    run_installer "$TARGET" --template=web
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "already exists"
    assert_file "$TARGET/keep.txt"
    assert_no_dir "$TARGET/src"
}

t_existing_nonempty_dir_with_force() {
    mkdir -p "$TARGET"
    touch "$TARGET/keep.txt"
    run_installer "$TARGET" --template=blank --force
    assert_exit 0 "$INSTALLER_EXIT"
    assert_dir "$TARGET/src/Core"
    assert_file "$TARGET/keep.txt"
}

t_empty_existing_dir_reused() {
    mkdir -p "$TARGET"
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"
    assert_dir "$TARGET/src/Core"
}

t_no_tty_no_template_fails() {
    run_installer "$TARGET"
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "No interactive terminal"
}

# ---------------------------------------------------------------- project shape

t_template_web_structure_and_config() {
    run_installer "$TARGET" --template=web
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/bin"
    assert_dir "$TARGET/public"
    assert_dir "$TARGET/src/Core"
    assert_dir "$TARGET/src/Modules/Phii"
    assert_file "$TARGET/public/index.php"
    assert_file "$TARGET/.env.example"
    assert_file "$TARGET/.env"
    assert_file "$TARGET/composer.json"
    assert_file "$TARGET/phpunit.xml"
    assert_file "$TARGET/config/app.php"

    assert_file "$TARGET/App/Frontend/AppLayout.phue"
    assert_file "$TARGET/App/Frontend/Pages/Home.phue"
    assert_file "$TARGET/App/Controllers/WebController.php"
    assert_file "$TARGET/App/Config/NavigationConfig.php"

    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Schii"
    assert_no_dir "$TARGET/src/Modules/Rest"
    assert_file "$TARGET/App/Models/User.php"

    assert_dir "$TARGET/tests/Auth"
    assert_dir "$TARGET/tests/Schii"
    assert_dir "$TARGET/tests/Support"
    assert_dir "$TARGET/storage/logs"
    assert_file "$TARGET/tests/App/.gitkeep"
}

t_framework_core_is_copied_and_ssr_pruned() {
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    assert_file "$TARGET/bin/phue"
    assert_file "$TARGET/bin/phue_test"
    assert_file "$TARGET/bin/phii"
    assert_file "$TARGET/phue"
    [[ -L "$TARGET/phue" ]] || fail "phue is not a symlink at root"
    assert_file "$TARGET/src/Core/Application.php"
    assert_file "$TARGET/src/Core/Module.php"
    assert_file "$TARGET/phue-docker"
    assert_file "$TARGET/docs/README.md"
    assert_file "$TARGET/docs/Core.md"
    assert_file "$TARGET/ssr/server.ts"
    assert_no_dir "$TARGET/ssr/node_modules"
}

t_tooling_rides_along_with_every_template() {
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    # Phii is the framework's generator: not a module, no id, impossible to
    # select — and present in every project regardless.
    assert_dir "$TARGET/src/Modules/Phii"
    assert_dir "$TARGET/tests/Phii"
    assert_contains "$TARGET/phpunit.xml" 'name="Phii"'
}

t_composer_json_is_named_after_the_project() {
    run_installer "$TARGET" --template=web
    assert_exit 0 "$INSTALLER_EXIT"

    php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        if (($d["name"] ?? "") !== "my-app") { fwrite(STDERR, "bad name\n"); exit(1); }
        if (($d["autoload"]["psr-4"]["Phue\\"] ?? "") !== "src/") { fwrite(STDERR, "bad psr4\n"); exit(1); }
        if (($d["autoload"]["psr-4"]["App\\"] ?? "") !== "App/") { fwrite(STDERR, "bad app psr4\n"); exit(1); }
        if (($d["scripts"]["phue"] ?? "") !== "php phue") { fwrite(STDERR, "bad script\n"); exit(1); }
    ' "$TARGET/composer.json" || fail "composer.json validation failed"
}

# ---------------------------------------------------------------- source guards

t_printf_arguments_match_their_format() {
    # bash reuses a format string when handed more arguments than it has
    # conversions, printing the format again instead of failing. That is how a
    # stray line of four spaces ends up in the middle of a menu — and it is
    # silent enough to survive being read several times without being noticed.
    #
    # Only self-contained printf calls are checked: a statement containing a
    # command substitution or a ${...} cannot be split into arguments by
    # anything simpler than a shell parser, and ${skipped%%|*} would be mistaken
    # for a pipeline. Those are covered behaviourally by the stray-whitespace
    # test below, which looks at what a user actually sees.
    python3 - "$INSTALLER" <<'PYGUARD'
import re
import sys

problems = []
pattern = re.compile(r"^\s*printf\s+'((?:[^'\\]|\\.)*)'\s*(.*?)\s*$")

for number, line in enumerate(open(sys.argv[1]), start=1):
    if "printf" not in line:
        continue

    if line.rstrip().endswith("\\") or "printf" in line[line.index("printf") + 6:]:
        continue  # continued, or printf nested inside an argument

    match = pattern.match(line)
    if not match:
        continue

    fmt, rest = match.group(1), match.group(2)

    if "&" in rest or ">" in rest or "|" in rest or "$(" in rest:
        continue

    arguments, current, quote = [], "", None
    for char in rest:
        if quote:
            current += char
            if char == quote:
                quote = None
            continue
        if char in "\"'":
            quote = char
            current += char
            continue
        if char == " ":
            if current:
                arguments.append(current)
            current = ""
        else:
            current += char

    if current:
        arguments.append(current)

    conversions, position = 0, 0
    while position < len(fmt):
        if fmt[position] == "%":
            position += 1
            if position < len(fmt) and fmt[position] == "%":
                position += 1
                continue
            stars = 0
            while position < len(fmt) and fmt[position] in "-+ #0123456789.*":
                stars += fmt[position] == "*"
                position += 1
            conversions += 1 + stars
        else:
            position += 1

    if len(arguments) != conversions:
        problems.append("line %d: %d conversions, %d arguments: %r" % (number, conversions, len(arguments), fmt))

print("\n".join(problems))
sys.exit(1 if problems else 0)
PYGUARD

    (( $? == 0 )) || fail "a printf has mismatched arguments, so it would print its format twice"
}

t_no_output_line_is_only_whitespace() {
    # The symptom of that printf bug, checked where it matters: every listing,
    # the dry run, and the menu itself. A line of nothing but padding is never
    # intentional output, and it is invisible to anyone reading the source.
    local command

    for command in "--list-templates" "--list-modules" "--dry-run --template=api"; do
        rm -rf "$TARGET"
        run_installer "$TARGET" $command

        if grep -qE '^[[:space:]]+$' <<< "$INSTALLER_OUT"; then
            fail "'$command' printed a line of nothing but spaces"
        fi
    done

    run_interactive '\e[B' "templates" "$TARGET"
    if grep -qE '^[[:space:]]+$' <<< "$SCREEN"; then
        fail "the menu drew a line of nothing but spaces"
    fi
}

t_no_hardcoded_module_table_is_left_behind() {
    # The original installer kept five parallel arrays of module names. Any
    # module added to the framework has to be installable without touching this
    # file, so a table here is the bug this rewrite removed.
    grep -qE '^declare -a? ?-?A?G? ?MODULE_(ORDER|CLASS_MAP|REQUIRES|TEST_DIR|DESCRIPTIONS)' "$INSTALLER" \
        && fail "a hardcoded module table has returned to the installer"
    return 0
}

t_every_template_module_is_installable() {
    local f modules id
    for f in "$FRAMEWORK"/tools/installer/templates/*.json; do
        mapfile -t modules < <(php -r '
            $d = json_decode(file_get_contents($argv[1]), true);
            foreach ($d["modules"] as $m) { echo $m, PHP_EOL; }
        ' "$f")

        for id in "${modules[@]}"; do
            [[ "$id" == "*" ]] && continue
            if [[ -z "$(grep -c "^$id$" <<< "$(framework_module_ids)")" ]]; then
                fail "template $(basename "$f" .json) names module '$id', which the framework does not ship"
            fi
        done
    done
}

# ---------------------------------------------------------------- interactive

t_menu_draws_once_and_does_not_stack() {
    run_interactive '\e[B' "templates" "$TARGET"

    # A screen that grows on every keypress scrolls the menu off the terminal
    # and leaves the user with a wall of stale frames. Counted in the screen a
    # user sees, which is the only place the duplication is real.
    local heading
    heading="$(grep -c "create a new project" <<< "$SCREEN" || true)"
    assert_count 1 "$heading" "template screen drawn"

    assert_output_contains "❯"
}

t_arrow_keys_move_the_cursor() {
    run_interactive '\e[B,\e[B,\e[B' "templates" "$TARGET"

    # Counted, not matched by name. Which entry is third depends on the template
    # files, and a test that hardcodes it fails every time a template is added
    # for reasons that have nothing to do with the keyboard. What must hold is
    # that three downs moved the pointer off the first row and left exactly one
    # row selected.
    local pointer first
    pointer="$(grep -n '❯' <<< "$SCREEN" | head -1)"
    first="$(grep -n '❯' <<< "$SCREEN" | wc -l | tr -d ' ')"

    assert_count 1 "$first" "rows with the cursor"
    [[ -n "$pointer" ]] || fail "no row carries the cursor"
    (( ${pointer%%:*} > 4 )) || fail "three downs did not move the cursor off the first row"
}

t_enter_installs_the_highlighted_template() {
    run_interactive '\r' "templates" "$TARGET"

    assert_output_contains "Creating Phue project"
    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Schii"
}

# The Custom row is last, so reaching it means one down per row above it. The
# row count comes from a menu that was really rendered, not from a number typed
# here: adding a template shifts every row below it, and a keyboard test that
# breaks when someone adds a preset is testing the wrong thing.
capture_menu() {
    run_interactive '' "templates" "$TARGET"
    MENU_ROWS="$(grep -cE '^ +[^ ]?[●○] ' <<< "$SCREEN")"
}

custom_key_sequence() {
    local downs="" index

    [[ -n "$MENU_ROWS" ]] || capture_menu

    for (( index = 1; index < MENU_ROWS; index++ )); do
        downs+='\e[B,'
    done

    printf '%s\r' "$downs"
}

t_custom_opens_the_module_screen() {
    run_interactive "$(custom_key_sequence)" "templates" "$TARGET"

    assert_output_contains "pick what this project needs"
    assert_output_contains "will install"
    assert_no_dir "$TARGET/src"
}

t_escape_returns_to_the_template_screen() {
    run_interactive "$(custom_key_sequence),\e" "templates" "$TARGET"

    # A wrong turn in a two-step wizard must not cost the user their place in it.
    assert_output_contains "create a new project"
    assert_output_lacks "pick what this project needs"
    assert_no_dir "$TARGET/src"
}

t_module_screen_toggles_and_installs() {
    run_interactive "$(custom_key_sequence), ,$(downs_to rest), ,\r" "templates" "$TARGET"

    assert_output_contains "Creating Phue project"
    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Rest"
    assert_no_dir "$TARGET/src/Modules/Websocket"
    assert_contains "$TARGET/config/app.php" "AuthModule"
}

t_module_screen_preview_shows_the_dependency() {
    # rest requires auth. Toggling rest alone must show both, because that is
    # what will be installed — a preview of the toggles rather than of the
    # outcome is a preview nobody can act on.
    run_interactive "$(custom_key_sequence),$(downs_to rest), " "templates" "$TARGET"

    assert_output_contains "will install"
    assert_output_contains "auth, rest"
}

t_select_all_then_none() {
    run_interactive "$(custom_key_sequence),a" "templates" "$TARGET"
    assert_output_contains "will install"

    run_interactive "$(custom_key_sequence),a,n" "templates" "$TARGET"
    assert_output_contains "no modules"
}

t_quit_cancels_without_writing() {
    run_interactive 'q' "templates" "$TARGET"

    assert_output_contains "Cancelled"
    assert_no_dir "$TARGET"
}

# ---------------------------------------------------------------- run

run_test "help exits zero"                              t_help_exits_zero
run_test "list-templates shows every template"          t_list_templates_shows_every_template
run_test "list-templates describes each template"       t_list_templates_describes_each_one
run_test "list-modules finds every module directory"    t_list_modules_finds_every_module_directory
run_test "list-modules reports tooling"                 t_list_modules_reports_tooling
run_test "list-modules describes every module"          t_list_modules_describes_every_module

run_test "auto-scan installs previously unlisted modules" t_modules_flag_installs_a_module_the_old_list_never_had
run_test "module ids are tolerated in any spelling"      t_module_ids_are_tolerantly_spelled
run_test "fullstack template tracks new modules"        t_fullstack_template_tracks_new_modules
run_test "scan finds tests wherever they live"          t_scan_finds_tests_wherever_they_live
run_test "scan finds config whatever it is named"       t_scan_finds_config_whatever_it_is_named
run_test "module without tests gets no empty testsuite" t_module_without_tests_gets_no_emptied_testsuite

run_test "auth companion is gated on its requirement"    t_auth_companion_is_gated_on_its_requirement
run_test "scaffold never references an absent module"   t_scaffolded_app_never_references_an_uninstalled_module

run_test "config/app.php lists exactly what was chosen"  t_config_app_lists_installed_modules_and_nothing_else
run_test "config/app.php without modules says so"       t_config_app_without_modules_says_so
run_test "phpunit suites match installed modules"        t_phpunit_suites_match_the_installed_modules
run_test "composer.json valid, suggests extensions"     t_composer_json_is_valid_and_suggests_what_is_needed
run_test "composer.json without extensions is clean"    t_composer_json_without_ext_modules_has_no_suggestions
run_test "generated files are stable between runs"      t_generated_files_are_stable_between_runs

run_test "dry run writes nothing"                       t_dry_run_writes_nothing
run_test "dry run matches the real install"             t_dry_run_matches_the_real_install
run_test "dependency resolution reports why"             t_dependency_resolution_reports_why
run_test "a non-framework --repo is rejected"           t_missing_framework_source_fails_clearly
run_test "a template naming an unknown module fails"    t_template_naming_an_unknown_module_is_rejected

run_test "invalid template fails"                       t_invalid_template_fails
run_test "invalid module fails with the valid ones"     t_invalid_module_fails_with_the_available_ones
run_test "template and modules together are rejected"   t_template_and_modules_together_are_rejected
run_test "unknown option fails"                         t_unknown_option_fails
run_test "existing non-empty dir fails"                 t_existing_nonempty_dir_fails
run_test "existing non-empty dir with --force"          t_existing_nonempty_dir_with_force
run_test "empty existing dir is reused"                 t_empty_existing_dir_reused
run_test "no tty without template fails"                t_no_tty_no_template_fails

run_test "template web: structure + config + isolation" t_template_web_structure_and_config
run_test "framework core copied, ssr pruned"            t_framework_core_is_copied_and_ssr_pruned
run_test "tooling ships with every template"            t_tooling_rides_along_with_every_template
run_test "composer.json valid + psr-4 + name"           t_composer_json_is_named_after_the_project

run_test "printf arguments match their format"          t_printf_arguments_match_their_format
run_test "no output line is only whitespace"             t_no_output_line_is_only_whitespace
run_test "no hardcoded module table returns"            t_no_hardcoded_module_table_is_left_behind
run_test "every template module is installable"         t_every_template_module_is_installable

run_test "menu draws once, does not stack"              t_menu_draws_once_and_does_not_stack
run_test "arrow keys move the cursor"                   t_arrow_keys_move_the_cursor
run_test "enter installs the highlighted template"      t_enter_installs_the_highlighted_template
run_test "custom opens the module screen"               t_custom_opens_the_module_screen
run_test "escape returns to the template screen"        t_escape_returns_to_the_template_screen
run_test "module screen toggles and installs"           t_module_screen_toggles_and_installs
run_test "preview shows the resolved dependency"        t_module_screen_preview_shows_the_dependency
run_test "select all, then none"                        t_select_all_then_none
run_test "quit cancels without writing"                 t_quit_cancels_without_writing

printf '%s\n' "────────────────────────────────────────────────────────────────────"
failed=$(( TOTAL - PASS ))

if (( TOTAL == 0 )); then
    printf '%sNo tests matched the filter%s\n' "$C_RED" "$C_NC"
    exit 1
fi

printf '%s%d passed%s, %s%d failed%s (%d total)\n' \
    "$C_GREEN" "$PASS" "$C_NC" "$C_RED" "$failed" "$C_NC" "$TOTAL"

(( failed == 0 )) || exit 1
