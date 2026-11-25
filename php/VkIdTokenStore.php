<?php
declare(strict_types=1);

namespace VkToGpt;

/**
 * Stores the VK ID user token pair in the existing MCP OAuth sqlite database
 * and transparently refreshes the short-lived access token.
 *
 * Secrets never leave this class: callers receive only the current access token.
 */
final class VkIdTokenStore
{
    private const TABLE = 'vkid_tokens';
    private const TOKEN_URL = 'https://api.vk.com/oauth/token';

    public function __construct(
        private readonly string $dbPath,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->clientId !== null && $this->clientId !== ''
            && $this->clientSecret !== null && $this->clientSecret !== '';
    }

    public function saveToken(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        if ($accessToken === '' || str_contains($accessToken, "\0")) {
            throw new \InvalidArgumentException('Invalid access token');
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO '.self::TABLE.' (id, access_token, refresh_token, expires_at, updated_at)
             VALUES (1, :access, :refresh, :expires, :updated)
             ON CONFLICT(id) DO UPDATE SET
               access_token = excluded.access_token,
               refresh_token = excluded.refresh_token,
               expires_at = excluded.expires_at,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':access' => $accessToken,
            ':refresh' => $refreshToken,
            ':expires' => time() + max(60, $expiresIn),
            ':updated' => time(),
        ]);
    }

    /**
     * Returns a usable VK user access token, refreshing it when expired.
     * Returns null when no token is stored or the refresh cannot complete.
     */
    public function freshAccessToken(): ?string
    {
        $row = $this->currentRow();
        if ($row === null) return null;
        $expiresAt = (int)$row['expires_at'];
        $access = (string)$row['access_token'];
        if (time() < $expiresAt - 120 && $access !== '') return $access;
        $refresh = (string)$row['refresh_token'];
        if ($refresh === '') return $access !== '' && time() < $expiresAt + 300 ? $access : null;
        $new = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        if ($new === null) return null;
        $this->saveToken((string)$new['access_token'], (string)$new['refresh_token'], (int)$new['expires_in']);
        return (string)$new['access_token'];
    }

    public function hasStoredToken(): bool
    {
        return $this->currentRow() !== null;
    }

    private function currentRow(): ?array
    {
        $stmt = $this->pdo()->query('SELECT access_token, refresh_token, expires_at FROM '.self::TABLE.' WHERE id = 1');
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Exchanges an authorization code or refresh token for a token pair. */
    public function exchange(array $params): ?array
    {
        if (!$this->hasCredentials()) return null;
        $payload = array_merge([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], $params);
        $response = $this->httpPost(self::TOKEN_URL, $payload);
        if ($response === null || !is_string($response['access_token'] ?? null)) return null;
        return [
            'access_token' => $response['access_token'],
            'refresh_token' => is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : ($params['refresh_token'] ?? ''),
            'expires_in' => is_numeric($response['expires_in'] ?? null) ? (int)$response['expires_in'] : 3600,
        ];
    }

    private function httpPost(string $url, array $params): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite:'.$this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL DEFAULT \'\',
                expires_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )'
        );
        return $pdo;
    }
}
