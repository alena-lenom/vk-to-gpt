<?php
declare(strict_types=1);

namespace VkToGpt;

final class VkApiException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $vkCode,
        public readonly ?string $credentialType = null,
    ) {
        parent::__construct($vkCode === null ? 'VK API transport or response failure' : 'VK API error '.$vkCode);
    }
}

final class MissingVkCredentialException extends \RuntimeException {}

final class VkClient
{
    private const METHODS = [
        'users.get', 'groups.getById', 'wall.get', 'wall.getById',
        'wall.getComments', 'stats.get', 'stats.getPostReach',
    ];
    private const WRITES = ['wall.post', 'wall.edit', 'wall.createComment'];

    /**
     * VKCOM vk-api-schema 5.199 access_token_type, represented as credential
     * classes configured by this server. Source: https://github.com/VKCOM/vk-api-schema/tree/333481bd082ad747d4873ef4a77f9247097eeef0 .
     * Target-specific policy chooses one configured credential from only the
     * types accepted by the method.
     */
    private const METHOD_TOKEN_TYPES = [
        'users.get' => ['user', 'group', 'service'],
        'groups.getById' => ['user', 'group', 'service'],
        'wall.get' => ['user', 'service'],
        'wall.getById' => ['user', 'service'],
        'wall.getComments' => ['user', 'service'],
        'stats.get' => ['user'],
        'stats.getPostReach' => ['user'],
        'wall.post' => ['user'],
        'wall.edit' => ['user'],
        'wall.createComment' => ['user', 'group'],
    ];

    private const ENV_BY_TYPE = [
        'user' => 'VK_USER_TOKEN',
        'group' => 'VK_COMMUNITY_TOKEN',
        'service' => 'VK_SERVICE_KEY',
    ];

    public function __construct(
        private readonly ?string $serviceKey,
        private readonly ?string $userToken,
        private readonly ?\Closure $fetch = null,
        private readonly ?\Closure $sleep = null,
        private readonly ?string $communityToken = null,
    ) {}

    public function call(string $method, array $params = [], ?string $writeTarget = null): mixed
    {
        $isWrite = in_array($method, self::WRITES, true);
        if (!in_array($method, self::METHODS, true) && !$isWrite) {
            throw new \RuntimeException('VK method blocked');
        }
        if ($isWrite && !in_array($writeTarget, ['personal', 'community'], true)) {
            throw new \RuntimeException('Write target is required');
        }

        [$credentialType, $token] = $this->selectCredential($method, $params, $writeTarget, $isWrite);
        return $this->request($method, $params, $token, $isWrite, $credentialType);
    }

    /**
     * Select exactly one key before making the request. A VK denial never
     * changes the credential actor; only a method's declared types are eligible.
     */
    private function selectCredential(string $method, array $params, ?string $writeTarget, bool $isWrite): array
    {
        $allowedTypes = self::METHOD_TOKEN_TYPES[$method] ?? [];
        if ($isWrite) {
            $preferredTypes = match ($method) {
                'wall.post' => $writeTarget === 'community' ? ['group', 'user'] : ['user'],
                'wall.edit' => ['user'],
                'wall.createComment' => $writeTarget === 'community' ? ['group', 'user'] : ['user'],
                default => [],
            };

            // One SAPI-RES-028 test proved a single text-only community wall.post
            // with one protected group key on API 5.199. Keep that narrow,
            // target-bound exception separate from the schema's user-only type.
            // It does not prove the PHP runtime has the same credential or grant a new write.
            if ($method === 'wall.post' && $writeTarget === 'community') {
                $allowedTypes[] = 'group';
            }
        } elseif (in_array($method, ['stats.get', 'stats.getPostReach'], true)) {
            $preferredTypes = ['user'];
        } elseif (in_array($method, ['wall.get', 'wall.getById', 'wall.getComments'], true)) {
            $ownerId = $this->wallOwnerId($method, $params);
            $preferredTypes = $ownerId !== null && $ownerId > 0
                ? ['user', 'service']
                : ['service', 'user'];
        } else {
            $preferredTypes = ['service', 'user', 'group'];
        }

        $orderedAllowedTypes = array_values(array_filter(
            $preferredTypes,
            static fn (string $type): bool => in_array($type, $allowedTypes, true),
        ));
        foreach ($orderedAllowedTypes as $type) {
            $token = match ($type) {
                'service' => $this->serviceKey,
                'user' => $this->userToken,
                'group' => $this->communityToken,
                default => null,
            };
            if (is_string($token) && $token !== '') {
                return [$type, $token];
            }
        }

        $environmentNames = array_map(
            static fn (string $type): string => self::ENV_BY_TYPE[$type],
            $orderedAllowedTypes,
        );
        $required = match (count($environmentNames)) {
            0 => 'no configured credential type is allowed for '.$method,
            1 => $environmentNames[0],
            2 => $environmentNames[0].' or '.$environmentNames[1],
            default => implode(', ', array_slice($environmentNames, 0, -1)).', or '.end($environmentNames),
        };
        throw new MissingVkCredentialException($required.' is not configured for '.$method);
    }

    private function wallOwnerId(string $method, array $params): ?int
    {
        if (isset($params['owner_id']) && is_int($params['owner_id'])) {
            return $params['owner_id'];
        }
        if ($method === 'wall.getById' && isset($params['posts']) && is_string($params['posts'])) {
            return (int) explode('_', explode(',', $params['posts'], 2)[0], 2)[0];
        }
        return null;
    }

    private function request(string $method, array $params, string $token, bool $isWrite, string $credentialType): mixed
    {
        unset($params['access_token'], $params['v'], $params['method']);
        $body = http_build_query(array_filter($params, static fn ($value) => $value !== null) + [
            'access_token' => $token,
            'v' => '5.199',
        ]);
        $maxAttempts = $isWrite ? 1 : 4;
        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            try {
                [$status, $json] = ($this->fetch ?? self::fetch(...))('https://api.vk.com/method/'.$method, $body);
                $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (VkApiException $error) {
                throw $error;
            } catch (\Throwable) {
                throw new VkApiException(null, $credentialType);
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('VK API HTTP error '.(int) $status);
            }
            if (!is_array($payload)) {
                throw new VkApiException(null, $credentialType);
            }
            if (isset($payload['error'])) {
                $code = $payload['error']['error_code'] ?? null;
                if ($code === 6 && !$isWrite && $attempt < 3) {
                    ($this->sleep ?? usleep(...))(500000 * 2 ** $attempt);
                    continue;
                }
                throw new VkApiException(is_int($code) ? $code : null, $credentialType);
            }
            if (!array_key_exists('response', $payload)) {
                throw new VkApiException(null, $credentialType);
            }
            return $payload['response'];
        }
        throw new \RuntimeException('VK API retry limit exceeded');
    }

    private static function fetch(string $url, string $body): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        try {
            $response = curl_exec($curl);
            if ($response === false) {
                throw new \RuntimeException('VK API transport failure');
            }
            return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response];
        } finally {
            curl_close($curl);
        }
    }
}
