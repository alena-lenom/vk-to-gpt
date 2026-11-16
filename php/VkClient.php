<?php
declare(strict_types=1);

namespace VkToGpt;

final class VkApiException extends \RuntimeException
{
    public function __construct(public readonly ?int $vkCode)
    {
        parent::__construct($vkCode === null ? 'VK API transport or response failure' : 'VK API error '.$vkCode);
    }
}

final class VkClient
{
    private const METHODS = [
        'users.get', 'groups.getById', 'wall.get', 'wall.getById',
        'wall.getComments', 'stats.get', 'stats.getPostReach',
    ];
    private const WRITES = ['wall.post', 'wall.edit'];
    private const FALLBACK_ERRORS = [27, 1051];

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

        $tokens = $this->tokenCandidates($method, $params, $writeTarget, $isWrite);
        if (!$tokens) {
            $required = match (true) {
                $isWrite && $method === 'wall.post' && $writeTarget === 'community' => 'VK_COMMUNITY_TOKEN',
                $isWrite, in_array($method, ['stats.get', 'stats.getPostReach'], true) => 'VK_USER_TOKEN',
                $method === 'groups.getById' => 'VK_SERVICE_KEY, VK_USER_TOKEN, or VK_COMMUNITY_TOKEN',
                default => 'VK_SERVICE_KEY or VK_USER_TOKEN',
            };
            throw new \RuntimeException($required.' is not configured');
        }

        foreach ($tokens as $index => $token) {
            try {
                return $this->request($method, $params, $token, $isWrite);
            } catch (VkApiException $error) {
                if (!$isWrite && isset($tokens[$index + 1]) && in_array($error->vkCode, self::FALLBACK_ERRORS, true)) {
                    continue;
                }
                throw new \RuntimeException($error->getMessage());
            }
        }
        throw new \RuntimeException('VK API fallback exhausted');
    }

    private function tokenCandidates(string $method, array $params, ?string $writeTarget, bool $isWrite): array
    {
        if ($isWrite) {
            $token = $method === 'wall.post' && $writeTarget === 'community' ? $this->communityToken : $this->userToken;
            return $token ? [$token] : [];
        }
        if (in_array($method, ['stats.get', 'stats.getPostReach'], true)) {
            return $this->uniqueTokens([$this->userToken]);
        }
        if ($method === 'groups.getById') {
            return $this->uniqueTokens([$this->serviceKey, $this->userToken, $this->communityToken]);
        }
        if ($method === 'users.get') {
            return $this->uniqueTokens([$this->serviceKey, $this->userToken]);
        }

        $ownerId = null;
        if (isset($params['owner_id']) && is_int($params['owner_id'])) {
            $ownerId = $params['owner_id'];
        } elseif ($method === 'wall.getById' && isset($params['posts']) && is_string($params['posts'])) {
            $ownerId = (int) explode('_', explode(',', $params['posts'], 2)[0], 2)[0];
        }
        return $ownerId !== null && $ownerId > 0
            ? $this->uniqueTokens([$this->userToken, $this->serviceKey])
            : $this->uniqueTokens([$this->serviceKey, $this->userToken]);
    }

    private function uniqueTokens(array $tokens): array
    {
        return array_values(array_unique(array_filter($tokens, static fn ($token) => is_string($token) && $token !== '')));
    }

    private function request(string $method, array $params, string $token, bool $isWrite): mixed
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
                throw new VkApiException(null);
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('VK API HTTP error '.(int) $status);
            }
            if (!is_array($payload)) {
                throw new VkApiException(null);
            }
            if (isset($payload['error'])) {
                $code = $payload['error']['error_code'] ?? null;
                if ($code === 6 && !$isWrite && $attempt < 3) {
                    ($this->sleep ?? usleep(...))(500000 * 2 ** $attempt);
                    continue;
                }
                throw new VkApiException(is_int($code) ? $code : null);
            }
            if (!array_key_exists('response', $payload)) {
                throw new VkApiException(null);
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
