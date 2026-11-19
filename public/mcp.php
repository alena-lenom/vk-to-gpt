<?php
declare(strict_types=1);

use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__).'/php/Security.php';

// Server-to-server only. No browser origins are required by this endpoint.
if (isset($_SERVER['HTTP_ORIGIN'])) {
    http_response_code(403);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: POST, GET, DELETE');
    exit;
}

try {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $staticBearer = VkToGpt\authorized(getenv('MCP_BEARER_TOKEN'), $authorization);
    if (!$staticBearer) {
        if (!preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/D', $authorization, $matches)) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer realm="vk-to-gpt", resource_metadata="https://vk.tattoomystery.ru/.well-known/oauth-protected-resource"');
            exit;
        }
        foreach (['MCP_OAUTH_DB','MCP_OAUTH_PUBLIC_KEY'] as $oauthSetting) {
            if (!is_string(getenv($oauthSetting)) || getenv($oauthSetting) === '') {
                http_response_code(401);
                header('WWW-Authenticate: Bearer realm="vk-to-gpt", resource_metadata="https://vk.tattoomystery.ru/.well-known/oauth-protected-resource"');
                exit;
            }
        }
    }
    require dirname(__DIR__).'/vendor/autoload.php';
    if (!$staticBearer) {
        require_once dirname(__DIR__).'/php/OAuth.php';
        $store = VkToGpt\OAuth\Store::open((string)getenv('MCP_OAUTH_DB'));
        $resourceServer = VkToGpt\OAuth\resourceServer($store, (string)getenv('MCP_OAUTH_PUBLIC_KEY'));
        $psrFactory = new Psr17Factory();
        $authHeaders = getallheaders() ?: [];
        $authHeaders['Authorization'] = $authorization;
        $authRequest = new ServerRequest('POST', 'https://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '/mcp'), $authHeaders, $psrFactory->createStreamFromString(''));
        try {
            $validated = $resourceServer->validateAuthenticatedRequest($authRequest);
        } catch (Throwable) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer error="invalid_token", resource_metadata="https://vk.tattoomystery.ru/.well-known/oauth-protected-resource"');
            exit;
        }
        $config = Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new Lcobucci\JWT\Signer\Rsa\Sha256(),
            Lcobucci\JWT\Signer\Key\InMemory::plainText('empty', 'empty'),
            Lcobucci\JWT\Signer\Key\InMemory::file('file://'.getenv('MCP_OAUTH_PUBLIC_KEY')),
        );
        $jwt = $config->parser()->parse($matches[1]);
        $claims = $jwt->claims();
        $audiences = $claims->get('aud', []);
        $scopes = $claims->get('scopes', []);
        if ($claims->get('iss') !== VkToGpt\OAuth\ISSUER
            || $claims->get('resource') !== VkToGpt\OAuth\RESOURCE
            || !is_array($audiences) || !in_array(VkToGpt\OAuth\RESOURCE, $audiences, true)
            || !is_array($scopes) || !in_array('vk.read', $scopes, true)) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer error="invalid_token"');
            exit;
        }
        $input = fopen('php://input', 'rb');
        $body = is_resource($input) ? stream_get_contents($input, 65537) : false;
        if (!is_string($body) || strlen($body) > 65536) {
            http_response_code(413);
            exit;
        }
        $rpc = json_decode($body, true);
        if (is_array($rpc) && ($rpc['method'] ?? null) === 'tools/call') {
            $tool = $rpc['params']['name'] ?? null;
            if (in_array($tool, ['vk_wall_post', 'vk_wall_edit', 'vk_wall_create_comment'], true) && !in_array('vk.write', $scopes, true)) {
                http_response_code(403);
                exit;
            }
        }
        $GLOBALS['VK_TO_GPT_MCP_BODY'] = $body;
    }
    require dirname(__DIR__).'/php/server.php';
    // Provision a private directory outside the document root; never use a shared temp directory.
    $sessions = getenv('MCP_SESSION_DIR');
    if (!$sessions || !is_dir($sessions) || !is_writable($sessions)
        || (fileperms($sessions) & 0077) !== 0
        || str_starts_with(realpath($sessions).'/', realpath(__DIR__).'/')) {
        throw new RuntimeException('Invalid private session directory');
    }
    umask(0077);
    $factory = new Psr17Factory();
    $headers = getallheaders();
    $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
    // Do not trust forwarded headers. The actual host is checked by SDK middleware.
    $request = new ServerRequest($_SERVER['REQUEST_METHOD'],
        $scheme.'://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '/mcp.php'),
        $headers, isset($GLOBALS['VK_TO_GPT_MCP_BODY'])
            ? $factory->createStreamFromString($GLOBALS['VK_TO_GPT_MCP_BODY'])
            : $factory->createStreamFromResource(fopen('php://input', 'rb')),
        serverParams: $_SERVER);
    $host = getenv('MCP_ALLOWED_HOST') ?: 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) {
        throw new RuntimeException('Invalid allowed host');
    }
    $transport = new StreamableHttpTransport($request, $factory, $factory, middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: [$host]),
    ], maxBodyBytes: 65536);
    $client = new VkToGpt\VkClient(getenv('VK_SERVICE_KEY') ?: null, getenv('VK_USER_TOKEN') ?: null, communityToken: getenv('VK_COMMUNITY_TOKEN') ?: null);
    $response = VkToGpt\buildServer($client, $sessions)->run($transport);
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header($name.': '.$value, false);
        }
    }
    echo $response->getBody();
} catch (Throwable) {
    http_response_code(503);
    // No exception, configuration, request data or credentials in output/logs.
    echo 'MCP unavailable';
}
