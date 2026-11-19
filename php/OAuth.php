<?php
declare(strict_types=1);

namespace VkToGpt\OAuth;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use PDO;
use RuntimeException;

const ISSUER = 'https://vk.tattoomystery.ru';
const RESOURCE = 'https://vk.tattoomystery.ru';
const CLIENT_REDIRECT = '#^https://chatgpt\\.com/(?:connector/oauth/[A-Za-z0-9_-]+|connector_platform_oauth_redirect)$#D';
const SCOPES = ['vk.read', 'vk.write'];

final class Scope implements ScopeEntityInterface
{
    use ScopeTrait;
    public function __construct(private string $identifier) {}
    public function getIdentifier(): string { return $this->identifier; }
}

final class Client implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function __construct(string $id, string $name, array $redirectUris)
    {
        $this->identifier = $id;
        $this->name = $name;
        $this->redirectUri = $redirectUris;
        $this->isConfidential = false;
    }
}

final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private CryptKeyInterface $privateKey;

    public function setPrivateKey(CryptKeyInterface $privateKey): void { $this->privateKey = $privateKey; }

    public function toString(): string
    {
        $key = InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? '');
        $config = Configuration::forAsymmetricSigner(new Sha256(), $key, InMemory::plainText('empty', 'empty'));
        $builder = $config->builder()
            ->issuedBy(ISSUER)
            ->permittedFor($this->getClient()->getIdentifier(), RESOURCE)
            ->identifiedBy($this->identifier)
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('resource', RESOURCE)
            ->withClaim('scopes', array_map(static fn(ScopeEntityInterface $scope): string => $scope->getIdentifier(), $this->getScopes()));

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}

final class AuthCode implements AuthCodeEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;
    use AuthCodeTrait;
}

final class RefreshToken implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}

final class Owner implements UserEntityInterface
{
    public function getIdentifier(): string { return 'vk-to-gpt-owner'; }
}

final class Store implements
    ClientRepositoryInterface,
    AccessTokenRepositoryInterface,
    AuthCodeRepositoryInterface,
    RefreshTokenRepositoryInterface,
    ScopeRepositoryInterface
{
    public function __construct(private PDO $db) {}

    public static function open(string $path): self
    {
        $directory = dirname($path);
        if (!is_dir($directory) || (fileperms($directory) & 0077) !== 0 || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid OAuth storage directory');
        }
        umask(0077);
        $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_clients (client_id TEXT PRIMARY KEY, name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_access_tokens (id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_auth_codes (id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)');
        chmod($path, 0600);
        return new self($db);
    }

    public function registerClient(string $name, array $redirectUris): string
    {
        $id = bin2hex(random_bytes(24));
        $statement = $this->db->prepare('INSERT INTO oauth_clients (client_id, name, redirect_uris, created_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$id, $name, json_encode($redirectUris, JSON_THROW_ON_ERROR), time()]);
        return $id;
    }

    public function clientCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn();
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $statement = $this->db->prepare('SELECT client_id,name,redirect_uris FROM oauth_clients WHERE client_id = ?');
        $statement->execute([$clientIdentifier]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $uris = json_decode((string)$row['redirect_uris'], true, 16, JSON_THROW_ON_ERROR);
        return is_array($uris) ? new Client((string)$row['client_id'], (string)$row['name'], $uris) : null;
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = $this->getClientEntity($clientIdentifier);
        return $client !== null && $clientSecret === null && in_array($grantType, ['authorization_code', 'refresh_token'], true);
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken();
        $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier ?? 'vk-to-gpt-owner');
        foreach ($scopes as $scope) $token->addScope($scope);
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->persist('oauth_access_tokens', $accessTokenEntity->getIdentifier(), $accessTokenEntity->getExpiryDateTime()->getTimestamp());
    }

    public function revokeAccessToken(string $tokenId): void { $this->revoke('oauth_access_tokens', $tokenId); }
    public function isAccessTokenRevoked(string $tokenId): bool { return $this->revoked('oauth_access_tokens', $tokenId); }

    public function getNewAuthCode(): AuthCodeEntityInterface { return new AuthCode(); }
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->persist('oauth_auth_codes', $authCodeEntity->getIdentifier(), $authCodeEntity->getExpiryDateTime()->getTimestamp());
    }
    public function revokeAuthCode(string $codeId): void { $this->revoke('oauth_auth_codes', $codeId); }
    public function isAuthCodeRevoked(string $codeId): bool { return $this->revoked('oauth_auth_codes', $codeId); }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface { return new RefreshToken(); }
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->persist('oauth_refresh_tokens', $refreshTokenEntity->getIdentifier(), $refreshTokenEntity->getExpiryDateTime()->getTimestamp());
    }
    public function revokeRefreshToken(string $tokenId): void { $this->revoke('oauth_refresh_tokens', $tokenId); }
    public function isRefreshTokenRevoked(string $tokenId): bool { return $this->revoked('oauth_refresh_tokens', $tokenId); }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, SCOPES, true) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        foreach ($scopes as $scope) {
            if (!$scope instanceof ScopeEntityInterface || !in_array($scope->getIdentifier(), SCOPES, true)) {
                $value = $scope instanceof ScopeEntityInterface ? $scope->getIdentifier() : 'invalid';
                throw OAuthServerException::invalidScope($value);
            }
        }
        return $scopes;
    }

    private function persist(string $table, string $id, int $expiresAt): void
    {
        $statement = $this->db->prepare("INSERT INTO $table (id, expires_at, revoked) VALUES (?, ?, 0)");
        $statement->execute([$id, $expiresAt]);
    }

    private function revoke(string $table, string $id): void
    {
        $statement = $this->db->prepare("UPDATE $table SET revoked=1 WHERE id=?");
        $statement->execute([$id]);
    }

    private function revoked(string $table, string $id): bool
    {
        $statement = $this->db->prepare("SELECT revoked,expires_at FROM $table WHERE id=?");
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return !is_array($row) || (int)$row['revoked'] !== 0 || (int)$row['expires_at'] < time();
    }
}

function authorizationServer(Store $store, string $privateKeyPath, string $encryptionKey): AuthorizationServer
{
    $key = base64_decode($encryptionKey, true);
    if (!is_string($key) || strlen($key) !== 32 || !is_file($privateKeyPath) || (fileperms($privateKeyPath) & 0077) !== 0) {
        throw new RuntimeException('OAuth keys are not configured safely');
    }
    $server = new AuthorizationServer($store, $store, $store, 'file://'.$privateKeyPath, $key);
    // Never grant write permission implicitly when a client omits its scope request.
    $server->setDefaultScope('vk.read');
    $authCode = new AuthCodeGrant($store, $store, new \DateInterval('PT5M'));
    $authCode->setRefreshTokenTTL(new \DateInterval('P30D'));
    $server->enableGrantType($authCode, new \DateInterval('PT1H'));
    $server->enableGrantType(new RefreshTokenGrant($store), new \DateInterval('PT1H'));
    return $server;
}

function resourceServer(Store $store, string $publicKeyPath): ResourceServer
{
    if (!is_file($publicKeyPath) || (fileperms($publicKeyPath) & 0077) !== 0) {
        throw new RuntimeException('OAuth public key is not configured safely');
    }
    return new ResourceServer($store, 'file://'.$publicKeyPath);
}
