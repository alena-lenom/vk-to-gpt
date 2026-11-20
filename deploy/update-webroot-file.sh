#!/usr/bin/env bash
# Guarded install/update of a single public endpoint file. Touches nothing else.
set -euo pipefail

operation=${1:?operation required}
webroot=${2:?webroot required}
stage=${3:?stage required}
revision=${4:?revision required}
name=${5:?name required}

[[ $revision =~ ^[0-9a-f]{40}$ ]]
[[ $name =~ ^[A-Za-z0-9._-]+$ ]]
[[ -d $webroot && ! -L $webroot ]]
source=$stage/$name
target=$webroot/$name
[[ -f $source && ! -L $source ]]
mkdir -p -- "$stage/backup"

replace_file() (
    src=$1 dest=$2
    next=$(mktemp "${dest}.XXXXXX") || exit 1
    trap 'rm -f -- "$next"' EXIT
    cp -p -- "$dest" "$next" 2>/dev/null || true
    cat -- "$src" > "$next"
    chmod 644 -- "$next"
    mv -fT -- "$next" "$dest"
)

existed=false
[[ -f $target && ! -L $target ]] && existed=true

case $operation in
    apply)
        [[ $(cat "$stage/REVISION") == "$revision" ]]
        $existed && cp -p -- "$target" "$stage/backup/$name"
        trap 'status=$?; if (( status )); then
            if [[ -f "$stage/backup/$name" ]]; then
                replace_file "$stage/backup/$name" "$target" || echo "Rollback failed; retain staging backup" >&2
            else
                rm -f -- "$target"
            fi
        fi; exit "$status"' EXIT
        replace_file "$source" "$target"
        ;;
    rollback)
        if [[ -f $stage/backup/$name ]]; then
            replace_file "$stage/backup/$name" "$target"
        else
            rm -f -- "$target"
        fi
        ;;
    *) exit 2 ;;
esac
