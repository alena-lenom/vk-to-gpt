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
