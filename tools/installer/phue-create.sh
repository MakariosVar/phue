#!/usr/bin/env bash
#
# Phue Project Creator — full framework installer.
#
# Creates a new Phue application from the framework source:
#   - framework core (bin, config, public, src/Core, Phii tooling, docs, docker, ssr)
#   - selected modules + their tests
#   - generated composer.json / config/app.php / phpunit.xml
#
# Usage:
#   phue-create.sh <project-dir> [--template=<name>] [options]
#   phue-create.sh <project-dir> --modules=auth,rest
#   phue-create.sh                       (interactive checklist)
#
# From a URL:
#   bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web

set -euo pipefail

# ---------------------------------------------------------------- constants

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -t 1 ]]; then
    C_BOLD=$'\033[1m'; C_GREEN=$'\033[32m'; C_BLUE=$'\033[34m'; C_YELLOW=$'\033[33m'; C_NC=$'\033[0m'
else
    C_BOLD=''; C_GREEN=''; C_BLUE=''; C_YELLOW=''; C_NC=''
fi

# ---------------------------------------------------------------- module metadata

readonly MODULE_ORDER=(auth schii rest server events compiler devtools)

declare -Ar MODULE_CLASS_MAP=(
    [auth]="Auth/AuthModule"
    [schii]="Schii/SchiiModule"
    [rest]="Rest/RestModule"
    [server]="Server/ServerModule"
    [events]="Events/EventModule"
    [compiler]="Compiler/CompilerModule"
    [devtools]="DevTool/DevToolsModule"
)

declare -Ar MODULE_REQUIRES=(
    [auth]=""
    [schii]=""
    [rest]="auth"
    [server]=""
    [events]=""
    [compiler]=""
    [devtools]=""
)

declare -Ar MODULE_TEST_DIR=(
    [auth]="Auth"
    [schii]="Schii"
    [rest]="Rest"
    [server]="Server"
    [events]="Events"
    [compiler]="Compiler"
    [devtools]="DevTools"
)

declare -Ar MODULE_DESCRIPTIONS=(
    [auth]="Authentication: AuthManager, Bearer auth, RBAC"
    [schii]="Server-driven UI: admin console, zero-controller REST, Phii"
    [rest]="REST API: attribute-driven #[Rest] CRUD endpoints"
    [server]="Swoole persistent server adapter"
    [events]="Events: Redis Streams bus + SSE"
    [compiler]="AOT compiler: wiring generation"
    [devtools]="Dev tools: debug toolbar, profiler"
)

# ---------------------------------------------------------------- helpers

die() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

need() {
    command -v "$1" >/dev/null 2>&1 || die "$2"
}

join_comma() {
    local IFS=,
    echo "$*"
}

usage() {
    cat <<'EOF'
Phue Project Creator

Usage: phue-create.sh [project-dir] [options]

Options:
  --template=<name>   Use a predefined template (see --list-templates)
  --modules=a,b,c     Custom module selection (non-interactive)
  --list-templates    List available templates and exit
  --repo=<path>       Path to the Phue framework source (default: this repo)
  --force             Allow creating inside a non-empty directory
  -h, --help          Show this help

Interactive mode:
  Run without --template/--modules to pick modules with an interactive
  checklist (up/down move, space toggles, enter confirms).

From a URL:
  bash <(curl -fsSL https://raw.githubusercontent.com/MakariosVar/phue/main/tools/installer/phue-create.sh) my-app --template=web

Run --list-templates to see the available templates.
EOF
}

# ---------------------------------------------------------------- templates

list_templates() {
    echo "Available templates:"
    echo ""
    local f name desc
    for f in "$TEMPLATES_DIR"/*.json; do
        name="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["name"] ?? basename($argv[1], ".json");' "$f")"
        desc="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["description"] ?? "";' "$f")"
        printf '  %-10s %s\n' "$name" "$desc"
    done
}

load_template() {
    local name="${1,,}"
    local file="$TEMPLATES_DIR/${name}.json"
    if [[ ! -f "$file" ]]; then
        echo "Error: Unknown template '$1'. Run with --list-templates to see available templates." >&2
        exit 1
    fi
    TEMPLATE_DESC="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["description"] ?? "";' "$file")"
    TEMPLATE_MODULES=()
    mapfile -t TEMPLATE_MODULES < <(php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach (($d["modules"] ?? []) as $m) { echo $m, PHP_EOL; }' "$file")
    local m
    for m in ${TEMPLATE_MODULES[@]+"${TEMPLATE_MODULES[@]}"}; do
        if [[ -z "${MODULE_CLASS_MAP[$m]:-}" ]]; then
            echo "Error: Template '$name' references unknown module '$m'." >&2
            exit 1
        fi
    done
}

# ---------------------------------------------------------------- selection

is_selected() {
    local id="$1" m
    for m in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
        [[ "$m" == "$id" ]] && return 0
    done
    return 1
}

parse_modules() {
    local raw="$1" m
    local raw_list=()
    IFS=',' read -ra raw_list <<< "$raw"
    for m in "${raw_list[@]}"; do
        m="${m//[[:space:]]/}"
        m="${m,,}"
        [[ -n "$m" ]] || continue
        if [[ -z "${MODULE_CLASS_MAP[$m]:-}" ]]; then
            echo "Error: Unknown module '$m'. Available modules: ${MODULE_ORDER[*]}" >&2
            exit 1
        fi
        SELECTED_MODULES+=("$m")
    done
    if (( ${#SELECTED_MODULES[@]} == 0 )); then
        die "Empty module selection."
    fi
}

resolve_dependencies() {
    local changed=1
    while (( changed )); do
        changed=0
        local id reqs r
        local req_list=()
        for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
            reqs="${MODULE_REQUIRES[$id]:-}"
            [[ -z "$reqs" ]] && continue
            IFS=',' read -ra req_list <<< "$reqs"
            for r in "${req_list[@]}"; do
                if ! is_selected "$r"; then
                    SELECTED_MODULES+=("$r")
                    echo "${C_YELLOW}ℹ${C_NC}  Module '${r}' is required by '${id}' — added automatically."
                    changed=1
                fi
            done
        done
    done
}

normalize_selection_order() {
    local ordered=() id
    for id in "${MODULE_ORDER[@]}"; do
        is_selected "$id" && ordered+=("$id")
    done
    SELECTED_MODULES=("${ordered[@]+"${ordered[@]}"}")
}

interactive_checklist() {
    if [[ ! -t 0 ]]; then
        echo "Error: No interactive terminal detected. Use --template=<name> or --modules=a,b,c (see --help)." >&2
        exit 1
    fi

    local count=${#MODULE_ORDER[@]}
    local -a sel=()
    local i
    for ((i = 0; i < count; i++)); do
        sel+=(0)
    done
    local cursor=0
    local first_draw=1
    local up_lines=$((count + 3))

    trap 'printf "\033[?25h" >&2; exit 130' INT

    printf '\033[?25l'

    draw() {
        (( first_draw )) || printf '\033[%dA\r' "$up_lines"
        printf '\033[K%s\n' "${C_BOLD}◆  Select the modules to install:${C_NC}"
        printf '\033[K%s\n' ""
        for ((i = 0; i < count; i++)); do
            local box='◻' prefix='  '
            (( ${sel[$i]} )) && box='◼'
            (( i == cursor )) && prefix='❯ '
            printf '\033[K%s\n' "${prefix}${box} ${MODULE_ORDER[$i]}  —  ${MODULE_DESCRIPTIONS[${MODULE_ORDER[$i]}]}"
        done
        printf '\033[K%s\n' ""
        printf '\033[K%s' "   ↑/↓ move · space toggle · enter confirm · q cancel"
        first_draw=0
    }

    while true; do
        draw
        local key=''
        IFS= read -rsn1 key
        case "$key" in
            $'\x1b')
                local k2='' k3=''
                read -rsn1 -t 0.05 k2 || true
                if [[ "${k2:-}" == '[' ]]; then
                    read -rsn1 -t 0.05 k3 || true
                    case "${k3:-}" in
                        A) cursor=$(( (cursor - 1 + count) % count )) ;;
                        B) cursor=$(( (cursor + 1) % count )) ;;
                    esac
                fi
                ;;
            ' ') sel[$cursor]=$(( 1 - ${sel[$cursor]} )) ;;
            'q'|'Q')
                printf '\033[?25h'
                printf '\n'
                die "Cancelled."
                ;;
            '')
                break
                ;;
        esac
    done

    trap - INT
    printf '\033[?25h'
    printf '\n'

    SELECTED_MODULES=()
    for ((i = 0; i < count; i++)); do
        (( ${sel[$i]} )) && SELECTED_MODULES+=("${MODULE_ORDER[$i]}")
    done
}

# ---------------------------------------------------------------- filesystem

copy_dir() {
    local src="$1" dst="$2"
    if [[ ! -d "$src" ]]; then
        echo "Warning: source directory not found, skipping: $src" >&2
        return 0
    fi
    mkdir -p "$dst"
    cp -a "$src/." "$dst/"
}

copy_file() {
    local src="$1" dst="$2"
    if [[ ! -f "$src" ]]; then
        echo "Warning: source file not found, skipping: $src" >&2
        return 0
    fi
    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"
}

gen_config_app() {
    local target="$1" entries="" id
    for id in "${MODULE_ORDER[@]}"; do
        if is_selected "$id"; then
            entries+="    \\Phue\\Modules\\${MODULE_CLASS_MAP[$id]//\//\\}::class,"$'\n'
        fi
    done
    cat > "$target/config/app.php" <<EOF
<?php

declare(strict_types=1);

return [
    'name' => 'Phue App',
    'env' => 'development',
    'debug' => true,
    'devTool' => true,
    'trustedProxies' => [],
    'modules' => [
$entries    ],
];
EOF
}

gen_phpunit() {
    local target="$1" suites="" id
    for id in "${MODULE_ORDER[@]}"; do
        if is_selected "$id"; then
            suites+="        <testsuite name=\"$id\">\n"
            suites+="            <directory suffix=\"Test.php\">tests/${MODULE_TEST_DIR[$id]}</directory>\n"
            suites+="        </testsuite>\n"
        fi
    done
    suites+="        <testsuite name=\"app\">\n            <directory suffix=\"Test.php\">tests/App</directory>\n        </testsuite>\n"
    cat > "$target/phpunit.xml" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
$suites    </testsuites>
</phpunit>
EOF
}

gen_composer() {
    local target="$1"
    local base="${PROJECT_DIR##*/}"
    local pkg_name
    pkg_name="$(printf '%s' "$base" | tr '[:upper:] ' '[:lower:]-')"
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

# ---------------------------------------------------------------- main

PROJECT_DIR=""
ARG_TEMPLATE=""
ARG_MODULES=""
ARG_REPO=""
ARG_FORCE=0
ARG_LIST=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --template=*) ARG_TEMPLATE="${1#*=}" ;;
        --modules=*) ARG_MODULES="${1#*=}" ;;
        --repo=*) ARG_REPO="${1#*=}" ;;
        --list-templates|--list) ARG_LIST=1 ;;
        --force) ARG_FORCE=1 ;;
        -h|--help) usage; exit 0 ;;
        -*)
            echo "Error: Unknown option '$1' (see --help)." >&2
            exit 1
            ;;
        *)
            if [[ -z "$PROJECT_DIR" ]]; then
                PROJECT_DIR="$1"
            else
                echo "Error: Unexpected extra argument '$1'." >&2
                exit 1
            fi
            ;;
    esac
    shift
done

need php "php is required to run the installer (and Phue itself)."

if [[ -n "$ARG_TEMPLATE" && -n "$ARG_MODULES" ]]; then
    die "Choose either --template=<name> or --modules=a,b,c, not both."
fi

# Resolve the framework source: this repo, --repo=<path>, or clone from URL.
REPO_ROOT=""
CLONE_PARENT=""
if [[ -n "$ARG_REPO" ]]; then
    REPO_ROOT="$ARG_REPO"
else
    REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
    if [[ ! -f "$REPO_ROOT/composer.json" || ! -d "$REPO_ROOT/src/Core" ]]; then
        REPO_ROOT=""
    fi
fi

if [[ -z "$REPO_ROOT" ]]; then
    PHUE_REPO_URL="${PHUE_REPO_URL:-https://github.com/MakariosVar/phue.git}"
    echo "${C_BLUE}ℹ${C_NC}  Phue source not found locally — cloning ${PHUE_REPO_URL} ..."
    need git "git is required to download the Phue framework."
    CLONE_PARENT="$(mktemp -d)"
    REPO_ROOT="$CLONE_PARENT/phue"
    git clone --depth 1 "$PHUE_REPO_URL" "$REPO_ROOT"
    trap 'rm -rf "$CLONE_PARENT"' EXIT
fi

if [[ ! -f "$REPO_ROOT/composer.json" || ! -d "$REPO_ROOT/src/Core" ]]; then
    die "'$REPO_ROOT' does not look like a Phue framework source. Use --repo=<path>."
fi

TEMPLATES_DIR="$REPO_ROOT/tools/installer/templates"
[[ -d "$TEMPLATES_DIR" ]] || TEMPLATES_DIR="$SCRIPT_DIR/templates"

if (( ARG_LIST )); then
    list_templates
    exit 0
fi

# Resolve the module selection.
SELECTED_MODULES=()
TEMPLATE_NAME="custom"
TEMPLATE_DESC="custom module selection"

if [[ -n "$ARG_TEMPLATE" ]]; then
    load_template "$ARG_TEMPLATE"
    TEMPLATE_NAME="$ARG_TEMPLATE"
    SELECTED_MODULES=("${TEMPLATE_MODULES[@]+"${TEMPLATE_MODULES[@]}"}")
elif [[ -n "$ARG_MODULES" ]]; then
    parse_modules "$ARG_MODULES"
else
    interactive_checklist
fi

resolve_dependencies
normalize_selection_order

# Resolve the target project directory.
if [[ -z "$PROJECT_DIR" ]]; then
    if [[ -t 0 ]]; then
        read -rp "${C_BOLD}◆  Project name:${C_NC} " PROJECT_DIR
        [[ -n "$PROJECT_DIR" ]] || die "Project name is required."
    else
        die "Missing project directory argument. Usage: phue-create.sh <project-dir> [options]"
    fi
fi

if [[ ! "$PROJECT_DIR" =~ ^[A-Za-z0-9._/-]+$ ]]; then
    die "Invalid project directory name '$PROJECT_DIR'."
fi

if [[ -e "$PROJECT_DIR" ]]; then
    if [[ -d "$PROJECT_DIR" && -z "$(ls -A "$PROJECT_DIR" 2>/dev/null)" ]]; then
        : # empty directory — reuse it
    elif (( ! ARG_FORCE )); then
        die "'$PROJECT_DIR' already exists and is not empty. Use --force to continue anyway."
    fi
fi

mkdir -p "$PROJECT_DIR"
TARGET="$(cd "$PROJECT_DIR" && pwd)"

echo ""
echo "${C_BOLD}◆  Creating Phue project at:${C_NC} $TARGET"
echo "${C_BOLD}◆  Template:${C_NC} $TEMPLATE_NAME — $TEMPLATE_DESC"
echo ""

# 1. Framework core
copy_dir "$REPO_ROOT/bin" "$TARGET/bin"
  ln -s bin/phue "$TARGET/phue" 2>/dev/null || true
  chmod +x "$TARGET/phue" 2>/dev/null || true
copy_dir "$REPO_ROOT/public" "$TARGET/public"
copy_dir "$REPO_ROOT/config" "$TARGET/config"
copy_dir "$REPO_ROOT/src/Core" "$TARGET/src/Core"
copy_dir "$REPO_ROOT/src/Modules/Phii" "$TARGET/src/Modules/Phii"
copy_dir "$REPO_ROOT/docs" "$TARGET/docs"
copy_dir "$REPO_ROOT/docker" "$TARGET/docker"
copy_file "$REPO_ROOT/ssr/server.ts" "$TARGET/ssr/server.ts"
copy_file "$REPO_ROOT/ssr/tsconfig.json" "$TARGET/ssr/tsconfig.json"
copy_file "$REPO_ROOT/ssr/package.json" "$TARGET/ssr/package.json"
copy_file "$REPO_ROOT/ssr/bun.lock" "$TARGET/ssr/bun.lock"
copy_file "$REPO_ROOT/phue-docker" "$TARGET/phue-docker"
chmod +x "$TARGET/phue-docker" 2>/dev/null || true
copy_file "$REPO_ROOT/phue-packages.json" "$TARGET/phue-packages.json"
copy_file "$REPO_ROOT/.env.example" "$TARGET/.env.example"
copy_file "$REPO_ROOT/.gitignore" "$TARGET/.gitignore"

# 2. App skeleton
copy_dir "$REPO_ROOT/App/Errors" "$TARGET/App/Errors"
copy_file "$REPO_ROOT/App/Frontend/AppLayout.phue" "$TARGET/App/Frontend/AppLayout.phue"
copy_dir "$REPO_ROOT/App/Frontend/Pages" "$TARGET/App/Frontend/Pages"
copy_file "$REPO_ROOT/App/Controllers/Controller.php" "$TARGET/App/Controllers/Controller.php"
copy_file "$REPO_ROOT/App/Controllers/WebController.php" "$TARGET/App/Controllers/WebController.php"
copy_file "$REPO_ROOT/App/Config/NavigationConfig.php" "$TARGET/App/Config/NavigationConfig.php"

# 3. Selected modules + tests + companions
for id in ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"}; do
    mod_dir="${MODULE_CLASS_MAP[$id]%%/*}"
    copy_dir "$REPO_ROOT/src/Modules/$mod_dir" "$TARGET/src/Modules/$mod_dir"
    copy_dir "$REPO_ROOT/tests/${MODULE_TEST_DIR[$id]}" "$TARGET/tests/${MODULE_TEST_DIR[$id]}"
    if [[ "$id" == "auth" ]]; then
        copy_file "$REPO_ROOT/App/Models/User.php" "$TARGET/App/Models/User.php"
    fi
    if [[ "$id" == "rest" ]]; then
        copy_file "$REPO_ROOT/App/Controllers/ApiController.php" "$TARGET/App/Controllers/ApiController.php"
    fi
done

copy_dir "$REPO_ROOT/tests/Support" "$TARGET/tests/Support"
mkdir -p "$TARGET/tests/App"
touch "$TARGET/tests/App/.gitkeep"

# 4. Storage skeleton
for sdir in assets cache generated logs; do
    mkdir -p "$TARGET/storage/$sdir"
    touch "$TARGET/storage/$sdir/.gitkeep"
done

# 5. Generated configuration
gen_config_app "$TARGET"
gen_phpunit "$TARGET"
gen_composer "$TARGET"

# 6. Local env
cp "$TARGET/.env.example" "$TARGET/.env"

mod_list="$(join_comma ${SELECTED_MODULES[@]+"${SELECTED_MODULES[@]}"})"
echo ""
echo "${C_GREEN}✓${C_NC} Project created successfully!"
echo ""
echo "  Path:    $TARGET"
echo "  Modules: ${mod_list:-<none>}"
echo ""
echo "${C_BOLD}Next steps:${C_NC}"
echo "  ./phue _DIR"
echo "  composer install"
echo "  ./phue serve"
echo ""
