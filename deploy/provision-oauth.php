<?php
declare(strict_types=1);

$base = $argv[1] ?? '';
$shared = $base.'/shared';
$oauth = $shared.'/oauth';
if ($base === '' || !is_dir($shared) || is_link($shared) || !extension_loaded('pdo_sqlite')) exit(1);
umask(0077);
foreach ([$oauth, $oauth.'/sessions'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) exit(1);
    chmod($directory, 0700);
    if (is_link($directory) || (fileperms($directory) & 0077) !== 0) exit(1);
}
$configPath = $shared.'/config.php';
if (is_link($configPath) || !is_file($configPath) || (fileperms($configPath) & 0077) !== 0) exit(1);
$config = require $configPath;
if (!is_array($config) || !is_string($config['MCP_BEARER_TOKEN'] ?? null) || strlen($config['MCP_BEARER_TOKEN']) < 32
    || ($config['MCP_ALLOWED_HOST'] ?? null) !== 'vk.tattoomystery.ru') exit(1);

$private = $oauth.'/private.pem';
$public = $oauth.'/public.pem';
if (!is_file($private) && !is_file($public)) {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($key === false || !openssl_pkey_export($key, $privatePem)) exit(1);
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !isset($details['key'])) exit(1);
    foreach ([[$private, $privatePem], [$public, $details['key']]] as [$path, $contents]) {
        if (file_put_contents($path, $contents, LOCK_EX) === false) exit(1);
        chmod($path, 0600);
    }
}
foreach ([$private, $public] as $path) {
    if (is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0) exit(1);
}
$config['MCP_OAUTH_DB'] = $oauth.'/oauth.sqlite';
$config['MCP_OAUTH_PRIVATE_KEY'] = $private;
$config['MCP_OAUTH_PUBLIC_KEY'] = $public;
$config['MCP_OAUTH_SESSION_DIR'] = $oauth.'/sessions';
if (!is_string($config['MCP_OAUTH_ENCRYPTION_KEY'] ?? null) || strlen(base64_decode($config['MCP_OAUTH_ENCRYPTION_KEY'], true) ?: '') !== 32) {
    $config['MCP_OAUTH_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
}
$temporary = $configPath.'.new';
if (file_put_contents($temporary, "<?php\nreturn ".var_export($config, true).";\n", LOCK_EX) === false) exit(1);
chmod($temporary, 0600);
if (!rename($temporary, $configPath)) { @unlink($temporary); exit(1); }
echo "oauth_private_storage=ready\n";
