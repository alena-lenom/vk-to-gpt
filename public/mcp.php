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

if (!VkToGpt\authorized(getenv('MCP_BEARER_TOKEN'), $_SERVER['HTTP_AUTHORIZATION'] ?? null)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="vk-to-gpt"');
    exit;
}

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
    require dirname(__DIR__).'/vendor/autoload.php';
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
        $headers, $factory->createStreamFromResource(fopen('php://input', 'rb')),
        serverParams: $_SERVER);
    $host = getenv('MCP_ALLOWED_HOST') ?: 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) {
        throw new RuntimeException('Invalid allowed host');
    }
    $transport = new StreamableHttpTransport($request, $factory, $factory, middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: [$host]),
    ], maxBodyBytes: 65536);
    $client = new VkToGpt\VkClient(getenv('VK_SERVICE_KEY') ?: null, getenv('VK_USER_TOKEN') ?: null);
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
