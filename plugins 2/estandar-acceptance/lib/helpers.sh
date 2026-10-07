#!/bin/zsh
# Estandar Acceptance Framework — Shared helpers

WORKSPACE="/Volumes/ESTANDAR_2026/ESTANDAR_STUDIO_IA/Plugins_Workspace/wp-content/plugins"

# Source-level check: grep pattern in file
check_source() {
    local file="$1" pattern="$2" label="$3"
    if grep -q "$pattern" "$file" 2>/dev/null; then
        pass "$label"
    else
        fail "$label"
    fi
}

# PHP lint check
check_php_lint() {
    local file="$1" label="$2"
    if php -l "$file" >/dev/null 2>&1; then
        pass "$label"
    else
        fail "$label"
    fi
}

# File existence check
check_file() {
    local file="$1" label="$2"
    if [ -f "$file" ]; then
        pass "$label"
    else
        fail "$label"
    fi
}
