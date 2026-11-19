#!/usr/bin/env bash
set -euo pipefail

script=$(cd -- "$(dirname -- "$0")/../../deploy" && pwd)/update-read-diagnostics.sh
root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
app=$root/app
stage=$root/stage
baseline=ce5982a365363c0319cc2b932c38621185f660e8
revision=1111111111111111111111111111111111111111
mkdir -p "$app/php" "$stage/php" "$root/shared/oauth" "$root/site"
existing=(php/VkClient.php php/server.php REVISION)
unchanged=(php/ReadOnly.php php/tools.json php/WallCollector.php php/SapiEventReader.php php/OAuth.php)
for file in "${existing[@]}"; do
    printf original > "$app/$file"
    printf revised > "$stage/$file"
done
printf '%s\n' "$baseline" > "$app/REVISION"
printf '%s\n' "$revision" > "$stage/REVISION"
for file in "${unchanged[@]}"; do
    printf protected > "$app/$file"
done
chmod 640 "$app/php/"*
printf config > "$root/shared/config.php"
printf key > "$root/shared/oauth/private.pem"
printf routing > "$root/site/.htaccess"
protected=("$root/shared/config.php" "$root/shared/oauth/private.pem" "$root/site/.htaccess")
for file in "${unchanged[@]}"; do protected+=("$app/$file"); done
before=$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")
if bash "$script" apply "$app" "$stage" 2222222222222222222222222222222222222222 "$revision"; then
    echo 'Accepted wrong installed revision' >&2
    exit 1
fi
test ! -e "$stage/backup"
mv "$stage/php/server.php" "$stage/php/server.php.disabled"
if bash "$script" apply "$app" "$stage" "$baseline" "$revision"; then
    echo 'Accepted missing staged source' >&2
    exit 1
fi
mv "$stage/php/server.php.disabled" "$stage/php/server.php"
test ! -e "$stage/backup"
bash "$script" apply "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$revision"
for file in "${existing[@]}"; do
    [[ $file == REVISION ]] || test "$(cat "$app/$file")" = revised
done
test "$(stat -c '%a' "$app/php/server.php")" = 640
test "$before" = "$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")"
bash "$script" rollback "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$baseline"
for file in "${existing[@]}"; do
    [[ $file == REVISION ]] || test "$(cat "$app/$file")" = original
done
test "$before" = "$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")"
echo 'Routing-only release guard, rollback and protected-file isolation: PASS'
