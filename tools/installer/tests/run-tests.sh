#!/usr/bin/env bash
#
# Phue-create installer test framework.
#
# Runs the installer in isolated tmp dirs, asserts the scaffolded
# project structure and configuration, and deletes the new app
# after every test.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER="$SCRIPT_DIR/../phue-create.sh"

if [[ -t 1 ]]; then
    C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_CYAN=$'\033[36m'; C_NC=$'\033[0m'
else
    C_GREEN=''; C_RED=''; C_CYAN=''; C_NC=''
fi

TOTAL=0
PASS=0
CURRENT_FAILURES=0

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
    if [[ -n "$TEST_TMP" && -d "$TEST_TMP" ]]; then
        rm -rf "$TEST_TMP"
    fi
    TEST_TMP=""
    TARGET=""
}

fail() {
    CURRENT_FAILURES=$((CURRENT_FAILURES + 1))
    printf '    %s✗%s %s\n' "$C_RED" "$C_NC" "$1"
}

# ---------------------------------------------------------------- assertions

assert_exit() {
    local expected="$1" actual="$2" msg="${3:-}"
    if (( expected != actual )); then
        fail "exit code: expected $expected, got $actual${msg:+ ($msg)}"
    fi
}

assert_file() {
    [[ -f "$1" ]] || fail "file not found: $1"
}

assert_no_file() {
    [[ ! -e "$1" ]] || fail "file unexpectedly exists: $1"
}

assert_dir() {
    [[ -d "$1" ]] || fail "directory not found: $1"
}

assert_no_dir() {
    [[ ! -d "$1" ]] || fail "directory unexpectedly exists: $1"
}

assert_contains() {
    grep -qF -- "$2" "$1" 2>/dev/null || fail "'$2' not found in $1"
}

assert_not_contains() {
    grep -qF -- "$2" "$1" 2>/dev/null && fail "'$2' unexpectedly found in $1"
    return 0
}

assert_output_contains() {
    grep -q -- "$1" <<< "$INSTALLER_OUT" || fail "output does not contain: '$1'"
}

# ---------------------------------------------------------------- runner

run_installer() {
    local out
    out="$(bash "$INSTALLER" "$@" </dev/null 2>&1)"
    INSTALLER_EXIT=$?
    INSTALLER_OUT="$out"
}

run_test() {
    local name="$1"
    shift
    TOTAL=$((TOTAL + 1))
    CURRENT_FAILURES=0
    printf '%s▶%s %s\n' "$C_CYAN" "$C_NC" "$name"
    "$@"
    teardown
    if (( CURRENT_FAILURES == 0 )); then
        PASS=$((PASS + 1))
        printf '    %s✓ passed%s\n\n' "$C_GREEN" "$C_NC"
    else
        printf '    %s✗ FAILED%s\n\n' "$C_RED" "$C_NC"
    fi
}

# ---------------------------------------------------------------- tests

t_help_exits_zero() {
    setup
    run_installer --help
    assert_exit 0 "$INSTALLER_EXIT"
    assert_output_contains "Usage: phue-create.sh"
}

t_list_templates_lists_all_six() {
    setup
    run_installer --list-templates
    assert_exit 0 "$INSTALLER_EXIT"
    for t in blank web api realtime fullstack edge; do
        assert_output_contains "$t"
    done
}

t_template_web_structure_and_config() {
    setup
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
    assert_file "$TARGET/src/Modules/Auth/AuthModule.php"
    assert_file "$TARGET/src/Modules/Schii/SchiiModule.php"
    assert_file "$TARGET/App/Models/User.php"

    assert_no_dir "$TARGET/src/Modules/Rest"
    assert_no_dir "$TARGET/src/Modules/Server"

    assert_contains "$TARGET/config/app.php" "AuthModule"
    assert_contains "$TARGET/config/app.php" "SchiiModule"
    assert_not_contains "$TARGET/config/app.php" "RestModule"

    assert_contains "$TARGET/phpunit.xml" 'name="auth"'
    assert_contains "$TARGET/phpunit.xml" 'name="schii"'
    assert_not_contains "$TARGET/phpunit.xml" 'name="rest"'

    assert_dir "$TARGET/tests/Auth"
    assert_dir "$TARGET/tests/Schii"
    assert_dir "$TARGET/tests/Support"
}

t_template_api_includes_auth_rest_schii() {
    setup
    run_installer "$TARGET" --template=api
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Rest"
    assert_dir "$TARGET/src/Modules/Schii"
    assert_file "$TARGET/App/Controllers/ApiController.php"

    assert_contains "$TARGET/config/app.php" "AuthModule"
    assert_contains "$TARGET/config/app.php" "RestModule"
    assert_contains "$TARGET/config/app.php" "SchiiModule"
}

t_template_blank_no_modules() {
    setup
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/src/Core"
    assert_no_dir "$TARGET/src/Modules/Auth"
    assert_no_dir "$TARGET/src/Modules/Schii"

    assert_contains "$TARGET/config/app.php" "'modules' => ["
    assert_not_contains "$TARGET/config/app.php" "AuthModule"
    assert_file "$TARGET/tests/App/.gitkeep"
}

t_template_fullstack_all_seven() {
    setup
    run_installer "$TARGET" --template=fullstack
    assert_exit 0 "$INSTALLER_EXIT"

    for d in Auth Schii Rest Server Events Compiler DevTool; do
        assert_dir "$TARGET/src/Modules/$d"
    done
    for c in AuthModule SchiiModule RestModule ServerModule EventModule CompilerModule DevToolsModule; do
        assert_contains "$TARGET/config/app.php" "$c"
    done
}

t_template_realtime() {
    setup
    run_installer "$TARGET" --template=realtime
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Events"
    assert_dir "$TARGET/src/Modules/Rest"
    assert_contains "$TARGET/config/app.php" "EventModule"
    assert_contains "$TARGET/config/app.php" "RestModule"
}

t_template_edge() {
    setup
    run_installer "$TARGET" --template=edge
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/src/Modules/Compiler"
    assert_dir "$TARGET/src/Modules/Server"
    assert_not_contains "$TARGET/config/app.php" "AuthModule"
}

t_modules_flag_custom_set() {
    setup
    run_installer "$TARGET" --modules=auth,server
    assert_exit 0 "$INSTALLER_EXIT"

    assert_dir "$TARGET/src/Modules/Auth"
    assert_dir "$TARGET/src/Modules/Server"
    assert_contains "$TARGET/config/app.php" "ServerModule"
    assert_not_contains "$TARGET/config/app.php" "SchiiModule"
}

t_dependency_auto_resolution() {
    setup
    run_installer "$TARGET" --modules=rest
    assert_exit 0 "$INSTALLER_EXIT"

    assert_contains "$TARGET/config/app.php" "RestModule"
    assert_contains "$TARGET/config/app.php" "AuthModule"
    assert_output_contains "added automatically"
}

t_invalid_template_fails() {
    setup
    run_installer "$TARGET" --template=bogus
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "Unknown template"
    assert_no_dir "$TARGET/src/Core"
}

t_invalid_module_fails() {
    setup
    run_installer "$TARGET" --modules=bogus
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "Unknown module"
    assert_no_dir "$TARGET/src/Core"
}

t_existing_nonempty_dir_fails() {
    setup
    mkdir -p "$TARGET"
    touch "$TARGET/keep.txt"
    run_installer "$TARGET" --template=web
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "already exists"
    assert_file "$TARGET/keep.txt"
    assert_no_dir "$TARGET/src"
}

t_empty_existing_dir_reused() {
    setup
    mkdir -p "$TARGET"
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"
    assert_dir "$TARGET/src/Core"
}

t_no_tty_no_template_fails() {
    setup
    run_installer "$TARGET"
    assert_exit 1 "$INSTALLER_EXIT"
    assert_output_contains "No interactive terminal"
}

t_composer_json_valid_and_named() {
    setup
    run_installer "$TARGET" --template=web
    assert_exit 0 "$INSTALLER_EXIT"

    php -r '
$d = json_decode(file_get_contents($argv[1]), true);
if (!is_array($d)) { fwrite(STDERR, "invalid json\n"); exit(1); }
if (($d["name"] ?? "") !== "my-app") { fwrite(STDERR, "bad name: " . $d["name"] . "\n"); exit(1); }
if (($d["autoload"]["psr-4"]["Phue\\"] ?? "") !== "src/") { fwrite(STDERR, "bad psr4\n"); exit(1); }
if (($d["autoload"]["psr-4"]["App\\"] ?? "") !== "App/") { fwrite(STDERR, "bad app psr4\n"); exit(1); }
' "$TARGET/composer.json" || fail "composer.json validation failed"
}

t_phpunit_suites_match_modules() {
    setup
    run_installer "$TARGET" --template=api
    assert_exit 0 "$INSTALLER_EXIT"

    assert_contains "$TARGET/phpunit.xml" 'name="auth"'
    assert_contains "$TARGET/phpunit.xml" 'name="rest"'
    assert_contains "$TARGET/phpunit.xml" 'name="schii"'
    assert_contains "$TARGET/phpunit.xml" 'name="app"'
    assert_not_contains "$TARGET/phpunit.xml" 'name="server"'
    assert_contains "$TARGET/phpunit.xml" "tests/Rest"
}

t_core_framework_copied_and_ssr_pruned() {
    setup
    run_installer "$TARGET" --template=blank
    assert_exit 0 "$INSTALLER_EXIT"

    assert_file "$TARGET/bin/phue"
    assert_file "$TARGET/bin/phue_test"
    assert_file "$TARGET/bin/phii"
    assert_file "$TARGET/src/Core/Application.php"
    assert_file "$TARGET/src/Core/Module.php"
    assert_file "$TARGET/phue-docker"
    assert_file "$TARGET/docs/Usage.md"
    assert_file "$TARGET/ssr/server.ts"
    assert_no_dir "$TARGET/ssr/node_modules"
    assert_dir "$TARGET/storage/logs"
    assert_file "$TARGET/storage/logs/.gitkeep"
}

# ---------------------------------------------------------------- run

run_test "help exits zero" t_help_exits_zero
run_test "list-templates lists all six templates" t_list_templates_lists_all_six
run_test "template web: structure + config + isolation" t_template_web_structure_and_config
run_test "template api: includes auth, rest, schii" t_template_api_includes_auth_rest_schii
run_test "template blank: no modules" t_template_blank_no_modules
run_test "template fullstack: all seven modules" t_template_fullstack_all_seven
run_test "template realtime: auth, events, rest" t_template_realtime
run_test "template edge: compiler, server, no auth" t_template_edge
run_test "--modules flag: custom selection" t_modules_flag_custom_set
run_test "dependency auto-resolution: rest adds auth" t_dependency_auto_resolution
run_test "invalid template fails" t_invalid_template_fails
run_test "invalid module fails" t_invalid_module_fails
run_test "existing non-empty dir fails" t_existing_nonempty_dir_fails
run_test "empty existing dir is reused" t_empty_existing_dir_reused
run_test "no tty without template fails" t_no_tty_no_template_fails
run_test "composer.json valid + psr-4 + name" t_composer_json_valid_and_named
run_test "phpunit suites match modules" t_phpunit_suites_match_modules
run_test "framework core copied + ssr pruned" t_core_framework_copied_and_ssr_pruned

printf '%s\n' "──────────────────────────────────────────"
failed=$((TOTAL - PASS))
printf '%s%d passed%s, %s%d failed%s (%d total)\n' "$C_GREEN" "$PASS" "$C_NC" "$C_RED" "$failed" "$C_NC" "$TOTAL"

if (( failed == 0 )); then
    exit 0
fi
exit 1
