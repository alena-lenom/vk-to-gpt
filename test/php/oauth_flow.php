<?php
declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "OAuth flow: skipped (PDO SQLite unavailable)\n");
    exit(0);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/php/OAuthHttp.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use VkToGpt\OAuth\Owner;
use VkToGpt\OAuth\Store;

function assertOAuth(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('OAuth flow check failed: '.$message);
}

$directory = sys_get_temp_dir().'/vk-to-gpt-oauth-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$privatePath = $directory.'/private.pem';
$publicPath = $directory.'/public.pem';
try {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    assertOAuth($key !== false && openssl_pkey_export($key, $privatePem), 'generate signing key');
    $details = openssl_pkey_get_details($key);
    assertOAuth(is_array($details) && isset($details['key']), 'export verification key');
    file_put_contents($privatePath, $privatePem, LOCK_EX);
    file_put_contents($publicPath, $details['key'], LOCK_EX);
    chmod($privatePath, 0600);
    chmod($publicPath, 0600);

    $store = Store::open($directory.'/oauth.sqlite');
    $redirect = 'https://chatgpt.com/connector/oauth/test_callback';
    $clientId = $store->registerClient('OAuth flow test', [$redirect]);
    $server = VkToGpt\OAuth\authorizationServer($store, $privatePath, base64_encode(random_bytes(32)));
    $verifier = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-._~';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'vk.read vk.write',
        'state' => 'csrf-state-test',
        'resource' => VkToGpt\OAuth\RESOURCE,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    $authorization = VkToGpt\OAuth\validateAuthorizationRequest(
        $server,
        new ServerRequest('GET', VkToGpt\OAuth\ISSUER.'/oauth/authorize'),
        $params,
    );
    $authorization->setUser(new Owner());
    $authorization->setAuthorizationApproved(true);
    $redirectResponse = VkToGpt\OAuth\issuerParameter($server->completeAuthorizationRequest($authorization, new Response()));
    parse_str((string)parse_url($redirectResponse->getHeaderLine('Location'), PHP_URL_QUERY), $returned);
    assertOAuth(($returned['state'] ?? null) === $params['state'] && ($returned['iss'] ?? null) === VkToGpt\OAuth\ISSUER, 'authorization response state and issuer');
    assertOAuth(is_string($returned['code'] ?? null), 'authorization code issued');

    $tokenRequest = static fn(array $body): ServerRequest => (new ServerRequest('POST', VkToGpt\OAuth\ISSUER.'/oauth/token'))
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody($body);
    $issuedResponse = $server->respondToAccessTokenRequest($tokenRequest([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'code' => $returned['code'],
        'code_verifier' => $verifier,
        'resource' => VkToGpt\OAuth\RESOURCE,
    ]), new Response());
    $issued = json_decode((string)$issuedResponse->getBody(), true, 16, JSON_THROW_ON_ERROR);
    assertOAuth(is_string($issued['access_token'] ?? null) && is_string($issued['refresh_token'] ?? null), 'access and refresh tokens issued');

    $jwt = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText('empty', 'empty'), InMemory::file('file://'.$publicPath))
        ->parser()->parse($issued['access_token']);
    $claims = $jwt->claims();
    assertOAuth($claims->get('iss') === VkToGpt\OAuth\ISSUER, 'issuer claim');
    assertOAuth($claims->get('resource') === VkToGpt\OAuth\RESOURCE, 'resource claim');
    assertOAuth(in_array(VkToGpt\OAuth\RESOURCE, $claims->get('aud'), true), 'resource audience');
    assertOAuth($claims->get('scopes') === ['vk.read', 'vk.write'], 'scope claims');

    $validated = VkToGpt\OAuth\resourceServer($store, $publicPath)->validateAuthenticatedRequest(
        (new ServerRequest('POST', VkToGpt\OAuth\RESOURCE.'/mcp'))->withHeader('Authorization', 'Bearer '.$issued['access_token']),
    );
    assertOAuth($validated->getAttribute('oauth_user_id') === 'vk-to-gpt-owner', 'signed access token validation');

    $sessions = $directory.'/mcp-sessions';
    mkdir($sessions, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    assertOAuth(is_resource($socket), 'reserve local HTTP port');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr((string)strrchr((string)$address, ':'), 1);
    $environment = getenv();
    assertOAuth(is_array($environment), 'read test process environment');
    $environment = array_merge($environment, [
        'MCP_BEARER_TOKEN' => 'local-static-bearer-for-oauth-test',
        'MCP_ALLOWED_HOST' => '127.0.0.1',
        'MCP_SESSION_DIR' => $sessions,
        'MCP_OAUTH_DB' => $directory.'/oauth.sqlite',
        'MCP_OAUTH_PUBLIC_KEY' => $publicPath,
        'VK_TO_GPT_TEST_DIAGNOSTICS' => '1',
        'VK_SERVICE_KEY' => '', 'VK_USER_TOKEN' => '', 'VK_COMMUNITY_TOKEN' => '',
        'VK_PERSONAL_OWNER_ID' => '', 'VK_COMMUNITY_ID' => '',
    ]);
    $serverProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', dirname(__DIR__, 2).'/public'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $serverPipes,
        dirname(__DIR__, 2),
        $environment,
    );
    assertOAuth(is_resource($serverProcess), 'start local MCP HTTP server');
    try {
        $baseUrl = 'http://127.0.0.1:'.$port.'/mcp.php';
        $headers = "Host: 127.0.0.1\r\nAuthorization: Bearer incorrect-test-token\r\n";
        $http = stream_context_create(['http' => ['method' => 'GET', 'header' => $headers, 'ignore_errors' => true, 'timeout' => 3]]);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            @file_get_contents($baseUrl, false, $http);
            if (isset($http_response_header[0])) break;
            usleep(100000);
        }
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) ? (int)$match[1] : 0;
        $phase = '';
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'X-VK-to-GPT-Test-Phase:') === 0) $phase = trim(substr($header, strlen('X-VK-to-GPT-Test-Phase:')));
        }
        assertOAuth($status === 401, 'invalid bearer rejected over HTTP (got '.$status.($phase === '' ? '' : ', phase '.$phase).')');

        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'oauth-smoke', 'version' => '1'],
        ]], JSON_THROW_ON_ERROR);
        $http = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Host: 127.0.0.1\r\nAuthorization: Bearer ".$issued['access_token']."\r\nContent-Type: application/json\r\nAccept: application/json, text/event-stream\r\nMCP-Protocol-Version: 2025-06-18\r\n",
            'content' => $initialize, 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $body = file_get_contents($baseUrl, false, $http);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match) ? (int)$match[1] : 0;
        assertOAuth($status === 200 && is_string($body) && str_contains($body, '"result"'), 'OAuth token initializes MCP over HTTP (got '.$status.')');
    } finally {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }

    $refreshedResponse = $server->respondToAccessTokenRequest($tokenRequest([
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $issued['refresh_token'],
        'resource' => VkToGpt\OAuth\RESOURCE,
    ]), new Response());
    $refreshed = json_decode((string)$refreshedResponse->getBody(), true, 16, JSON_THROW_ON_ERROR);
    assertOAuth(is_string($refreshed['access_token'] ?? null), 'refresh exchange');
    echo "OAuth flow: authorization code, PKCE, resource audience and refresh passed\n";
} finally {
    unset($server, $store);
    foreach (glob($directory.'/*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($directory);
}
