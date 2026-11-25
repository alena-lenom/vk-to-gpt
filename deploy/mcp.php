<?php
declare(strict_types=1);
// Managed VK to GPT entrypoint; secrets stay outside the document root.
ini_set('display_errors', '0');
header('Cache-Control: no-store');
try {
    $base = '/var/www/u3641285/data/vk-to-gpt';
    $config = require $base.'/shared/config.php';
    if (!is_array($config)) throw new RuntimeException('Invalid configuration');
    foreach (['MCP_BEARER_TOKEN','MCP_ALLOWED_HOST','MCP_SESSION_DIR','MCP_OAUTH_DB','MCP_OAUTH_PRIVATE_KEY','MCP_OAUTH_PUBLIC_KEY','MCP_OAUTH_ENCRYPTION_KEY','MCP_OAUTH_SESSION_DIR','VK_ID_CLIENT_ID','VK_ID_CLIENT_SECRET','VK_ID_SCOPE','VK_SERVICE_KEY','VK_USER_TOKEN','VK_COMMUNITY_TOKEN','VK_PERSONAL_OWNER_ID','VK_COMMUNITY_ID','VK_SAPI_EVENTS_DIR'] as $name) {
        if (!isset($config[$name])) { putenv($name); continue; }
        if (!is_string($config[$name]) || str_contains($config[$name], "\0")) throw new RuntimeException('Invalid configuration');
        putenv($name.'='.$config[$name]);
    }
    // A VK ID user token stored in the OAuth database stands in for an empty VK_USER_TOKEN.
    if (getenv('VK_USER_TOKEN') === false || getenv('VK_USER_TOKEN') === '') {
        try {
            require_once $base.'/app/php/VkIdTokenStore.php';
            $db = getenv('MCP_OAUTH_DB');
            if (is_string($db) && $db !== '') {
                $token = (new \VkToGpt\VkIdTokenStore(
                    $db,
                    getenv('VK_ID_CLIENT_ID') ?: null,
                    getenv('VK_ID_CLIENT_SECRET') ?: null,
                ))->freshAccessToken();
                if (is_string($token) && $token !== '') putenv('VK_USER_TOKEN='.$token);
            }
        } catch (Throwable) {
            // Token fallback is best-effort; tools report the missing credential themselves.
        }
    }
    $_SERVER['HTTP_AUTHORIZATION'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    require $base.'/app/public/mcp.php';
} catch (Throwable) {
    http_response_code(503);
    echo 'MCP unavailable';
}
