#!/usr/bin/env bash
# Guarded update of bounded VK read code only; never rewrite OAuth, secrets or routes.
set -euo pipefail

operation=$1
app=$2
stage=$3
baseline=$4
revision=$5

[[ $baseline =~ ^[0-9a-f]{40}$ && $revision =~ ^[0-9a-f]{40}$ && $baseline != "$revision" ]]
[[ -d $app && ! -L $app && -d $app/php && ! -L $app/php ]]
existing=(php/VkClient.php php/ReadOnly.php php/tools.json php/server.php REVISION)
created=(php/WallCollector.php php/SapiEventReader.php)
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

create_file() (
    source=$1
    dest=$2
    [[ ! -e $dest && ! -L $dest ]]
    next=$(mktemp "${dest}.XXXXXX") || exit 1
    trap 'rm -f -- "$next"' EXIT
    cat -- "$source" > "$next"
    chmod 0640 "$next"
    mv -T -- "$next" "$dest"
)

restore_files() {
    local file
    for file in "${created[@]}"; do
        if [[ -e $app/$file || -L $app/$file ]]; then
            [[ -f $app/$file && ! -L $app/$file ]] || return 1
            cmp -s -- "$stage/$file" "$app/$file" || return 1
        fi
    done
    for file in "${existing[@]}"; do
        replace_file "$stage/backup/$file" "$app/$file" || return 1
    done
    for file in "${created[@]}"; do
        if [[ -e $app/$file ]]; then
            rm -- "$app/$file" || return 1
        fi
    done
}

case $operation in
    apply)
        [[ $(cat "$app/REVISION") == "$baseline" ]]
        [[ $(cat "$stage/REVISION") == "$revision" ]]
        for file in "${existing[@]}" "${created[@]}"; do
            [[ -f $stage/$file && ! -L $stage/$file ]]
        done
        for file in "${created[@]}"; do
            [[ ! -e $app/$file && ! -L $app/$file ]]
        done
        mkdir -- "$stage/backup" "$stage/backup/php"
        for file in "${existing[@]}"; do
            cp -p -- "$app/$file" "$stage/backup/$file"
        done
        trap 'status=$?; if (( status )); then restore_files || echo "Rollback failed; retain staging backup" >&2; fi; exit "$status"' EXIT
        for file in "${existing[@]}"; do
            replace_file "$stage/$file" "$app/$file"
        done
        for file in "${created[@]}"; do
            create_file "$stage/$file" "$app/$file"
        done
        ;;
    rollback)
        [[ $(cat "$app/REVISION") == "$revision" ]]
        [[ $(cat "$stage/backup/REVISION") == "$baseline" ]]
        restore_files
        ;;
    *) exit 2 ;;
esac
