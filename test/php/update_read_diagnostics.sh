#!/usr/bin/env bash
set -euo pipefail

script=$(cd -- "$(dirname -- "$0")/../../deploy" && pwd)/update-read-diagnostics.sh
root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
app=$root/app
stage=$root/stage
baseline=ff56156313ed89d9ad42dc8dec7dd49c716ab78c
revision=1111111111111111111111111111111111111111
mkdir -p "$app/php" "$stage/php" "$root/shared/oauth" "$root/site"
printf original > "$app/php/VkClient.php"
printf original > "$app/php/server.php"
printf '%s\n' "$baseline" > "$app/REVISION"
printf revised > "$stage/php/VkClient.php"
printf revised > "$stage/php/server.php"
printf '%s\n' "$revision" > "$stage/REVISION"
chmod 640 "$app/php/VkClient.php" "$app/php/server.php"
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
bash "$script" apply "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$revision"
test "$(cat "$app/php/VkClient.php")" = revised
test "$(cat "$app/php/server.php")" = revised
test "$(stat -c '%a' "$app/php/server.php")" = 640
bash "$script" rollback "$app" "$stage" "$baseline" "$revision"
test "$(cat "$app/REVISION")" = "$baseline"
test "$(cat "$app/php/VkClient.php")" = original
test "$(cat "$app/php/server.php")" = original
test "$before" = "$(sha256sum -- "${protected[@]}"; stat -c '%a:%u:%g:%i' -- "${protected[@]}")"
echo 'Code-only update and rollback preserve OAuth, settings and file permissions'
