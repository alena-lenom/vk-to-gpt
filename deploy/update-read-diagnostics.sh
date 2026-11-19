#!/usr/bin/env bash
# Replace only the PHP read diagnostics and the installed revision marker.
set -euo pipefail

operation=$1
app=$2
stage=$3
baseline=$4
revision=$5

[[ $baseline =~ ^[0-9a-f]{40}$ && $revision =~ ^[0-9a-f]{40}$ && $baseline != "$revision" ]]
[[ -d $app && ! -L $app && -d $app/php && ! -L $app/php ]]
files=(php/VkClient.php php/server.php REVISION)
for file in "${files[@]}"; do
    [[ -f $app/$file && ! -L $app/$file ]]
done

replace_file() (
    source=$1
    dest=$2
    next=$(mktemp "${dest}.XXXXXX") || exit 1
    trap 'rm -f -- "$next"' EXIT
    cp -p -- "$dest" "$next" || exit 1
    [[ $(stat -c '%a:%u:%g' -- "$next") == "$(stat -c '%a:%u:%g' -- "$dest")" ]] || exit 1
    cat -- "$source" > "$next" || exit 1
    [[ $(stat -c '%a:%u:%g' -- "$next") == "$(stat -c '%a:%u:%g' -- "$dest")" ]] || exit 1
    mv -fT -- "$next" "$dest"
)

restore_files() {
    local file
    for file in "${files[@]}"; do
        replace_file "$stage/backup/$file" "$app/$file" || return 1
    done
}

case $operation in
    apply)
        [[ $(cat "$app/REVISION") == "$baseline" ]]
        [[ $(cat "$stage/REVISION") == "$revision" ]]
        for file in "${files[@]}"; do
            [[ -f $stage/$file && ! -L $stage/$file ]]
        done
        mkdir -- "$stage/backup" "$stage/backup/php"
        for file in "${files[@]}"; do
            cp -p -- "$app/$file" "$stage/backup/$file"
        done
        trap 'status=$?; if (( status )); then restore_files || echo "Rollback failed; retain staging backup" >&2; fi; exit "$status"' EXIT
        for file in "${files[@]}"; do
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
