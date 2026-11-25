<?php
declare(strict_types=1);

namespace VkToGpt;

/**
 * Stores the VK ID user token pair in the existing MCP OAuth sqlite database
 * and transparently refreshes the short-lived (1 hour) access token.
 *
 * VK ID scheme (no SDK, backend exchange):
 *   authorize: GET  https://id.vk.ru/authorize?response_type=code&...
 *   exchange:  POST https://id.vk.ru/oauth2/auth (grant_type=authorization_code,
 *              code, code_verifier, device_id, service_token for confidential apps)
 *
 * Secrets never leave this class: callers receive only the current access token.
 */
final class VkIdTokenStore
{
    private const TABLE = 'vkid_tokens';
    private const TOKEN_URL = 'https://id.vk.ru/oauth2/auth';

    /** Details of the last failed exchange (VK error body), for server-side logs only. */
    private ?string $lastError = null;

    public function __construct(
        private readonly string $dbPath,
        private readonly ?string $clientId,
        private readonly ?string $serviceToken,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->clientId !== null && $this->clientId !== '';
    }

    public function hasServiceToken(): bool
    {
        return $this->serviceToken !== null && $this->serviceToken !== '';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function saveToken(string $accessToken, string $refreshToken, int $expiresIn, string $deviceId = ''): void
    {
        if ($accessToken === '' || str_contains($accessToken, "\0")) {
            throw new \InvalidArgumentException('Invalid access token');
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO '.self::TABLE.' (id, access_token, refresh_token, device_id, expires_at, updated_at)
             VALUES (1, :access, :refresh, :device, :expires, :updated)
             ON CONFLICT(id) DO UPDATE SET
               access_token = excluded.access_token,
               refresh_token = excluded.refresh_token,
               device_id = excluded.device_id,
               expires_at = excluded.expires_at,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':access' => $accessToken,
            ':refresh' => $refreshToken,
            ':device' => $deviceId,
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
        $new = $this->exchange([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'device_id' => (string)$row['device_id'],
        ]);
        if ($new === null) return null;
        $this->saveToken(
            (string)$new['access_token'],
            (string)$new['refresh_token'],
            (int)$new['expires_in'],
            (string)$new['device_id'],
        );
        return (string)$new['access_token'];
    }

    public function hasStoredToken(): bool
    {
        return $this->currentRow() !== null;
    }

    private function currentRow(): ?array
    {
        $stmt = $this->pdo()->query('SELECT access_token, refresh_token, device_id, expires_at FROM '.self::TABLE.' WHERE id = 1');
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Exchanges an authorization code or refresh token for a token pair.
     * $params must include grant_type and the grant-specific fields.
     */
    public function exchange(array $params): ?array
    {
        $this->lastError = null;
        if (!$this->hasCredentials()) return null;
        $payload = array_merge(['client_id' => $this->clientId], $params);
        // Confidential VK ID apps authenticate the exchange with the service token.
        if ($this->hasServiceToken()) {
            $payload['service_token'] = $this->serviceToken;
        }
        $response = $this->httpPost(self::TOKEN_URL, $payload);
        if ($response === null) return null;
        if (!is_string($response['access_token'] ?? null)) {
            $error = is_string($response['error'] ?? null) ? $response['error'] : 'unknown';
            $description = is_string($response['error_description'] ?? null) ? $response['error_description'] : '';
            $hint = is_string($response['error_hint'] ?? null) ? $response['error_hint'] : '';
            $this->lastError = json_encode(
                ['error' => $error, 'error_description' => $description, 'error_hint' => $hint],
                JSON_UNESCAPED_UNICODE,
            );
            return null;
        }
        return [
            'access_token' => $response['access_token'],
            'refresh_token' => is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : ($params['refresh_token'] ?? ''),
            'expires_in' => is_numeric($response['expires_in'] ?? null) ? (int)$response['expires_in'] : 3600,
            'device_id' => is_string($response['device_id'] ?? null) ? $response['device_id'] : ($params['device_id'] ?? ''),
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
        if (!is_string($body) || $body === '') {
            $this->lastError = json_encode(['transport' => 'empty or failed response', 'http_code' => $status]);
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->lastError = json_encode(['transport' => 'non-JSON body', 'http_code' => $status]);
            return null;
        }
        if ($status < 200 || $status >= 300) {
            // Caller inspects decoded error fields via exchange().
            return $decoded;
        }
        return $decoded;
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
                device_id TEXT NOT NULL DEFAULT \'\',
                expires_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )'
        );
        return $pdo;
    }
}
