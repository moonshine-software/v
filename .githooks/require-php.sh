# Sourced by the git hooks. GUI git clients often start hooks with a PATH whose
# first php is the system one; prefer a package-manager PHP 8.5+ when present.

php_is_supported() {
    [ -x "$1" ] && [ "$("$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)" -ge 80500 ]
}

if ! php_is_supported "$(command -v php || true)"; then
    for dir in /opt/homebrew/bin /usr/local/bin; do
        if php_is_supported "$dir/php"; then
            PATH="$dir:$PATH"
            export PATH
            break
        fi
    done
fi

if ! php_is_supported "$(command -v php || true)"; then
    echo "PHP 8.5+ is required by the git hooks; put it first on PATH." >&2
    exit 1
fi
