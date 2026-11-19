#!/usr/bin/env bash
# Guarded update of post-counter analytics only; keep OAuth, tokens, routing, and other tools untouched.
set -euo pipefail

operation=${1:?operation required}
app=${2:?app required}
stage=${3:?stage required}
baseline=${4:?baseline required}
revision=${5:?revision required}

[[ $baseline =~ ^[0-9a-f]{40}$ && $revision =~ ^[0-9a-f]{40}$ && $baseline != "$revision" ]]
[[ -d $app && ! -L $app && -d $app/php && ! -L $app/php ]]
existing=(php/WallCollector.php REVISION)
for file in "${existing[@]}"; do
    [[ -f $app/$file && ! -L $app/$file ]]
done

replace_file() (
    source=$1
    dest=$2
    next=$(mktemp "${dest}.XXXXXX") || exit 1
    trap 'rm -f -- "$next"' EXIT
    cp -p -- "$dest" "$next"
    [[ $(stat -c '%a:%u:%g' -- "$next") == "$(stat -c '%a:%u:%g' -- "$dest")" ]]
    cat -- "$source" > "$next"
    [[ $(stat -c '%a:%u:%g' -- "$next") == "$(stat -c '%a:%u:%g' -- "$dest")" ]]
    mv -fT -- "$next" "$dest"
)

restore_files() {
    local file
    for file in "${existing[@]}"; do
        replace_file "$stage/backup/$file" "$app/$file" || return 1
    done
}

case $operation in
    apply)
        [[ $(cat "$app/REVISION") == "$baseline" ]]
        [[ $(cat "$stage/REVISION") == "$revision" ]]
        for file in "${existing[@]}"; do
            [[ -f $stage/$file && ! -L $stage/$file ]]
        done
        mkdir -- "$stage/backup" "$stage/backup/php"
        for file in "${existing[@]}"; do
            cp -p -- "$app/$file" "$stage/backup/$file"
        done
        trap 'status=$?; if (( status )); then restore_files || echo "Rollback failed; retain staging backup" >&2; fi; exit "$status"' EXIT
        for file in "${existing[@]}"; do
            replace_file "$stage/$file" "$app/$file"
        done
        ;;
    rollback)
        [[ $(cat "$app/REVISION") == "$revision" ]]
        [[ $(cat "$stage/backup/REVISION") == "$baseline" ]]
        restore_files
        ;;
    *) exit 2 ;;
esac
