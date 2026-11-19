<?php
declare(strict_types=1);

namespace VkToGpt\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

require_once __DIR__.'/OAuth.php';

function oauthConfig(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
        throw new \RuntimeException('OAuth configuration is incomplete');
    }
    return $value;
}

function requestFromGlobals(?array $query = null, ?array $body = null): ServerRequestInterface
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $request = new ServerRequest(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'https://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '/'),
        is_array($headers) ? $headers : [],
        null,
        serverParams: $_SERVER,
    );
    return $request->withQueryParams($query ?? $_GET)->withParsedBody($body ?? $_POST);
}

function response(int $status, string $body, string $type = 'application/json; charset=utf-8', array $headers = []): ResponseInterface
{
    return new Response($status, array_merge([
        'Content-Type' => $type,
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ], $headers), $body);
}

function emit(ResponseInterface $response): never
{
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) header($name.': '.$value, false);
    }
    echo (string)$response->getBody();
    exit;
}

function issuerParameter(ResponseInterface $response): ResponseInterface
{
    $location = $response->getHeaderLine('Location');
    if ($location === '') return $response;
    $parts = parse_url($location);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return $response;
    $query = [];
    if (isset($parts['query'])) parse_str($parts['query'], $query);
    $query['iss'] = ISSUER;
    $new = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
        .($parts['path'] ?? '').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    return $response->withHeader('Location', $new);
}

function protectedResourceMetadata(): array
{
    return [
        'resource' => RESOURCE,
        'authorization_servers' => [ISSUER],
        'scopes_supported' => SCOPES,
        'bearer_methods_supported' => ['header'],
    ];
}

function authorizationServerMetadata(): array
{
    return [
        'issuer' => ISSUER,
        'authorization_endpoint' => ISSUER.'/oauth/authorize',
        'token_endpoint' => ISSUER.'/oauth/token',
        'registration_endpoint' => ISSUER.'/oauth/register',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'code_challenge_methods_supported' => ['S256'],
        'authorization_response_iss_parameter_supported' => true,
        'client_id_metadata_document_supported' => false,
        'scopes_supported' => SCOPES,
    ];
}

function allowedRedirectUri(mixed $uri): bool
{
    if (!is_string($uri) || strlen($uri) > 512) return false;
    $parts = parse_url($uri);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== 'chatgpt.com'
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }
    return preg_match(CLIENT_REDIRECT, $uri) === 1;
}

function registerClient(Store $store): ResponseInterface
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return response(405, json_encode(['error' => 'invalid_request'], JSON_THROW_ON_ERROR), headers: ['Allow' => 'POST']);
    }
    $inputStream = fopen('php://input', 'rb');
    $raw = is_resource($inputStream) ? stream_get_contents($inputStream, 8193) : false;
    if (!is_string($raw) || strlen($raw) > 8192) {
        return response(413, json_encode(['error' => 'invalid_client_metadata'], JSON_THROW_ON_ERROR));
    }
    $input = json_decode($raw, true);
    if (!is_array($input) || !is_array($input['redirect_uris'] ?? null) || count($input['redirect_uris']) < 1 || count($input['redirect_uris']) > 3) {
        return response(400, json_encode(['error' => 'invalid_client_metadata'], JSON_THROW_ON_ERROR));
    }
    $uris = array_values(array_unique($input['redirect_uris']));
    foreach ($uris as $uri) {
        if (!allowedRedirectUri($uri)) return response(400, json_encode(['error' => 'invalid_redirect_uri'], JSON_THROW_ON_ERROR));
    }
    if ($store->clientCount() >= 50) return response(429, json_encode(['error' => 'registration_limit_reached'], JSON_THROW_ON_ERROR));
    $name = is_string($input['client_name'] ?? null) ? trim($input['client_name']) : '';
    if ($name === '') $name = 'ChatGPT private app';
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'ChatGPT private app';
    $name = mb_substr($name, 0, 100, 'UTF-8');
    $clientId = $store->registerClient($name, $uris);
    return response(201, json_encode([
        'client_id' => $clientId,
        'client_id_issued_at' => time(),
        'client_secret_expires_at' => 0,
        'redirect_uris' => $uris,
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], JSON_THROW_ON_ERROR));
}

function oauthSessionStart(): void
{
    $directory = oauthConfig('MCP_OAUTH_SESSION_DIR');
    if (!is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0
        || str_starts_with((string)realpath($directory).'/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__).'/')) {
        throw new \RuntimeException('Invalid OAuth session directory');
    }
    session_save_path($directory);
    session_name('vk_to_gpt_oauth');
    session_set_cookie_params(['lifetime' => 900, 'path' => '/oauth/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new \RuntimeException('OAuth session unavailable');
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function oauthParams(array $input): array
{
    $params = [];
    foreach (['client_id','redirect_uri','response_type','scope','state','code_challenge','code_challenge_method','resource'] as $key) {
        if (isset($input[$key]) && is_string($input[$key])) $params[$key] = $input[$key];
    }
    return $params;
}

function validateAuthorizationRequest(\League\OAuth2\Server\AuthorizationServer $server, ServerRequestInterface $request, array $params): \League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface
{
    if (empty($params['state']) || ($params['resource'] ?? null) !== RESOURCE
        || ($params['code_challenge_method'] ?? null) !== 'S256'
        || !isset($params['code_challenge']) || !is_string($params['code_challenge'])) {
        throw OAuthServerException::invalidRequest('state', 'State, resource, and S256 PKCE are required');
    }
    $request = $request->withQueryParams($params);
    return $server->validateAuthorizationRequest($request);
}

function authHtml(array $params, string $csrf, string $step, string $message = '', string $client = '', array $scopes = []): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $hidden = '';
    foreach ($params as $key => $value) {
        if (is_string($value)) $hidden .= '<input type="hidden" name="'.$escape($key).'" value="'.$escape($value).'">';
    }
    $csrfInput = '<input type="hidden" name="csrf" value="'.$escape($csrf).'">';
    $error = $message === '' ? '' : '<p class="error" role="alert">'.$escape($message).'</p>';
    if ($step === 'login') {
        $body = '<h1>Подключить VK to GPT</h1><p>Введите MCP bearer из закрытой конфигурации хостинга. Значение не сохраняется и используется только для проверки владельца.</p>'.$error
            .'<form method="post" action="/oauth/authorize">'.$hidden.$csrfInput.'<label for="owner-secret">MCP bearer</label><input id="owner-secret" name="owner_secret" type="password" autocomplete="off" required autofocus><button type="submit" name="action" value="login">Продолжить</button></form>';
    } else {
        $labels = [];
        foreach ($scopes as $scope) $labels[] = $scope === 'vk.read' ? 'Чтение данных VK' : ($scope === 'vk.write' ? 'Публикация и редактирование постов' : $scope);
        $body = '<h1>Разрешить доступ</h1><p>Приложение: '.$escape($client).'</p><p>Доступы: '.$escape(implode(', ', $labels)).'</p>'.$error
            .'<form method="post" action="/oauth/authorize">'.$hidden.$csrfInput.'<button type="submit" name="action" value="approve">Разрешить</button> <button type="submit" name="action" value="deny">Отказать</button></form>';
    }
    return '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VK to GPT — доступ</title><style>body{font:16px system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:24px;color:#17202a}main{border:1px solid #ddd;border-radius:14px;padding:28px}p{line-height:1.5;color:#4b5563}label{display:block;margin:20px 0 8px}input[type=password]{box-sizing:border-box;width:100%;padding:12px;border:1px solid #bbb;border-radius:8px}button{padding:11px 16px;margin-top:16px;border:0;border-radius:8px;background:#2557d6;color:white;cursor:pointer}.error{color:#a11}</style><main>'.$body.'</main></html>';
}

function authorize(Store $store, \League\OAuth2\Server\AuthorizationServer $server): ResponseInterface
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) return response(405, 'Method not allowed', 'text/plain; charset=utf-8', ['Allow' => 'GET, POST']);
    $input = $method === 'GET' ? $_GET : $_POST;
    $params = oauthParams($input);
    try {
        $request = requestFromGlobals($params, $method === 'POST' ? $_POST : []);
        $authorization = validateAuthorizationRequest($server, $request, $params);
    } catch (Throwable $error) {
        return $error instanceof OAuthServerException
            ? issuerParameter($error->generateHttpResponse(new Response()))
            : response(400, 'Invalid authorization request', 'text/plain; charset=utf-8');
    }

    oauthSessionStart();
    $csrf = (string)$_SESSION['csrf'];
    $loggedIn = (int)($_SESSION['owner_until'] ?? 0) > time();
    if ($method === 'POST') {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
            return response(400, 'Invalid session', 'text/plain; charset=utf-8');
        }
        if (!$loggedIn && ($_POST['action'] ?? '') === 'login') {
            $provided = $_POST['owner_secret'] ?? null;
            $configured = getenv('MCP_BEARER_TOKEN');
            if (!is_string($provided) || !is_string($configured) || !hash_equals($configured, $provided)) {
                return response(401, authHtml($params, $csrf, 'login', 'Не удалось продолжить. Проверьте секрет в закрытой конфигурации.'), 'text/html; charset=utf-8');
            }
            session_regenerate_id(true);
            $_SESSION['owner_until'] = time() + 900;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $csrf = (string)$_SESSION['csrf'];
            return response(200, authHtml($params, $csrf, 'consent', '', $authorization->getClient()->getName(), array_map(static fn($scope): string => $scope->getIdentifier(), $authorization->getScopes())), 'text/html; charset=utf-8');
        }
        if ($loggedIn && in_array($_POST['action'] ?? '', ['approve', 'deny'], true)) {
            $authorization->setUser(new Owner());
            $authorization->setAuthorizationApproved($_POST['action'] === 'approve');
            try {
                $completed = issuerParameter($server->completeAuthorizationRequest($authorization, new Response()));
                return $completed;
            } catch (OAuthServerException $error) {
                return issuerParameter($error->generateHttpResponse(new Response()));
            }
        }
    }
    return response(200, authHtml($params, $csrf, $loggedIn ? 'consent' : 'login', '', $authorization->getClient()->getName(), array_map(static fn($scope): string => $scope->getIdentifier(), $authorization->getScopes())), 'text/html; charset=utf-8');
}

function token(Store $store, \League\OAuth2\Server\AuthorizationServer $server): ResponseInterface
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return response(405, json_encode(['error' => 'invalid_request'], JSON_THROW_ON_ERROR), headers: ['Allow' => 'POST']);
    $body = $_POST;
    if (($body['resource'] ?? null) !== RESOURCE) {
        return response(400, json_encode(['error' => 'invalid_target'], JSON_THROW_ON_ERROR));
    }
    try {
        return $server->respondToAccessTokenRequest(requestFromGlobals([], $body), new Response());
    } catch (OAuthServerException $error) {
        return $error->generateHttpResponse(new Response());
    }
}

function dispatch(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $allowedHost = oauthConfig('MCP_ALLOWED_HOST');
    if (!is_string($path) || strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== strtolower($allowedHost)
        || (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== ISSUER)) {
        emit(response(403, 'Forbidden', 'text/plain; charset=utf-8'));
    }

    if (in_array($path, ['/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/mcp/.well-known/oauth-protected-resource'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') emit(response(405, 'Method not allowed', 'text/plain; charset=utf-8', ['Allow' => 'GET']));
        emit(response(200, json_encode(protectedResourceMetadata(), JSON_THROW_ON_ERROR)));
    }
    if ($path === '/.well-known/oauth-authorization-server') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') emit(response(405, 'Method not allowed', 'text/plain; charset=utf-8', ['Allow' => 'GET']));
        emit(response(200, json_encode(authorizationServerMetadata(), JSON_THROW_ON_ERROR)));
    }

    try {
        $store = Store::open(oauthConfig('MCP_OAUTH_DB'));
        if ($path === '/oauth/register') emit(registerClient($store));
        $server = authorizationServer($store, oauthConfig('MCP_OAUTH_PRIVATE_KEY'), oauthConfig('MCP_OAUTH_ENCRYPTION_KEY'));
        if ($path === '/oauth/authorize') emit(authorize($store, $server));
        if ($path === '/oauth/token') emit(token($store, $server));
    } catch (OAuthServerException $error) {
        emit($error->generateHttpResponse(new Response()));
    } catch (Throwable) {
        emit(response(503, 'OAuth unavailable', 'text/plain; charset=utf-8'));
    }
    emit(response(404, 'Not found', 'text/plain; charset=utf-8'));
}
