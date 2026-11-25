<?php
declare(strict_types=1);

namespace VkToGpt;

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__).'/php/VkIdTokenStore.php';

/**
 * Minimal VK ID OAuth user flow (no SDK, backend code exchange):
 *   /vkid                 -> start (redirect to https://id.vk.ru/authorize, PKCE + state)
 *   /vkid?code=...        -> callback (VK returns here; exchange code, store tokens)
 *
 * The redirect_uri must EXACTLY match the app's trusted redirect URL in the VK ID
 * console (scheme + host + path, case-sensitive), so no action= parameter is used.
 *
 * Environment (see .env.example):
 *   VK_ID_CLIENT_ID     - numeric "ID приложения" from the VK ID console
 *   VK_ID_SERVICE_TOKEN - "Сервисный ключ доступа" of the app (confidential exchange)
 *   VK_ID_SCOPE         - requested scope (default: vkid.personal_info)
 */

function envOrFail(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
        http_response_code(503);
        exit('VK ID flow is not configured');
    }
    return $value;
}

function baseUrl(): string
{
    return 'https://'.envOrFail('MCP_ALLOWED_HOST');
}

function store(): VkIdTokenStore
{
    return new VkIdTokenStore(
        envOrFail('MCP_OAUTH_DB'),
        getenv('VK_ID_CLIENT_ID') ?: null,
        getenv('VK_ID_SERVICE_TOKEN') ?: null,
    );
}

function sessionDir(): string
{
    $dir = getenv('MCP_OAUTH_SESSION_DIR');
    if (!is_string($dir) || $dir === '') $dir = sys_get_temp_dir().'/vkid';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>'
        .htmlspecialchars($title, ENT_QUOTES)
        .'</title></head><body style="font-family:sans-serif;max-width:32em;margin:4em auto;line-height:1.5">'
        .$body.'</body></html>';
    exit;
}

$action = (string)($_GET['action'] ?? (isset($_GET['code']) ? 'callback' : 'start'));

if ($action === 'start') {
    $clientId = envOrFail('VK_ID_CLIENT_ID');
    if (!store()->hasCredentials()) {
        page('VK ID', '<h2>VK ID не настроен</h2><p>Заполните VK_ID_CLIENT_ID в конфиге сервера.</p>');
    }
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $sessionFile = sessionDir().'/vkid_'.hash('sha256', $state);
    file_put_contents($sessionFile, json_encode(['verifier' => $verifier, 'expires' => time() + 600]), LOCK_EX);
    @chmod($sessionFile, 0600);

    $redirectUri = baseUrl().'/vkid';
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'scope' => getenv('VK_ID_SCOPE') ?: 'vkid.personal_info',
    ], '', '&', PHP_QUERY_RFC3986);
    header('Location: https://id.vk.ru/authorize?'.$query, true, 302);
    exit;
}

if ($action === 'callback') {
    $code = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    $deviceId = (string)($_GET['device_id'] ?? '');
    $sessionFile = sessionDir().'/vkid_'.hash('sha256', $state);
    $session = is_file($sessionFile) ? json_decode((string)file_get_contents($sessionFile), true) : null;
    @unlink($sessionFile);
    if (!is_array($session) || ($session['expires'] ?? 0) < time() || $code === '') {
        page('VK ID', '<h2>Ошибка</h2><p>Сессия авторизации истекла или код отсутствует. Начните заново: откройте /vkid.</p>');
    }
    $result = store()->exchange([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'code_verifier' => (string)$session['verifier'],
        'device_id' => $deviceId,
    ]);
    if ($result === null) {
        page('VK ID', '<h2>Ошибка обмена кода</h2><p>VK ID не выдал токен. Проверьте сервисный ключ, уровень конфиденциальности приложения и IP-адрес сервера в настройках, затем повторите.</p>');
    }
    store()->saveToken(
        (string)$result['access_token'],
        (string)$result['refresh_token'],
        (int)$result['expires_in'],
        (string)$result['device_id'],
    );
    page('VK ID', '<h2>Готово</h2><p>Пользовательский токен получен и сохранён. Статистика, wall.getById и wall.edit теперь доступны через эндпоинт.</p>');
}

http_response_code(404);
exit('Unknown action');
