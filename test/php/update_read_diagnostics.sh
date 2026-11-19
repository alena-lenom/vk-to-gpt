#!/usr/bin/env bash
set -euo pipefail

script=$(cd -- "$(dirname -- "$0")/../../deploy" && pwd)/update-read-diagnostics.sh
root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
app=$root/app
stage=$root/stage
baseline=8bcff6a73ffe2b9375d75d8f197b3eb4c5c51a6e
revision=1111111111111111111111111111111111111111
mkdir -p "$app/php" "$stage/php" "$root/shared/oauth" "$root/site"
existing=(php/VkClient.php php/ReadOnly.php php/tools.json php/server.php REVISION)
created=(php/WallCollector.php php/SapiEventReader.php)
for file in "${existing[@]}"; do
    printf original > "$app/$file"
    printf revised > "$stage/$file"
done
printf '%s\n' "$baseline" > "$app/REVISION"
printf '%s\n' "$revision" > "$stage/REVISION"
for file in "${created[@]}"; do
    printf new-source > "$stage/$file"
done
chmod 640 "$app/php/"*
printf oauth > "$app/php/OAuth.php"
printf config > "$root/shared/config.php"
printf key > "$root/shared/oauth/private.pem"
printf routing > "$root/site/.htaccess"
protected=("$app/php/OAuth.php" "$root/shared/config.php" "$root/shared/oauth/private.pem" "$root/site/.htaccess")
before=$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")
if bash "$script" apply "$app" "$stage" 2222222222222222222222222222222222222222 "$revision"; then
    echo 'Accepted wrong installed revision' >&2
    exit 1
fi
test ! -e "$stage/backup"
printf collision > "$app/php/WallCollector.php"
if bash "$script" apply "$app" "$stage" "$baseline" "$revision"; then
    echo 'Overwrote an existing new-file target' >&2
    exit 1
fi
test "$(cat "$app/php/WallCollector.php")" = collision
test ! -e "$stage/backup"
rm "$app/php/WallCollector.php"
bash "$script" apply "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$revision"
for file in "${existing[@]}"; do
    [[ $file == REVISION ]] || test "$(cat "$app/$file")" = revised
done
for file in "${created[@]}"; do
    test "$(cat "$app/$file")" = new-source
    test "$(stat -c '%a' "$app/$file")" = 640
done
test "$(stat -c '%a' "$app/php/server.php")" = 640
bash "$script" rollback "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$baseline"
for file in "${existing[@]}"; do
    [[ $file == REVISION ]] || test "$(cat "$app/$file")" = original
done
for file in "${created[@]}"; do
    test ! -e "$app/$file"
done
test "$before" = "$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")"
echo 'Bounded read adapter update/rollback preserve OAuth, protected settings and permissions'
