<?php
declare(strict_types=1);

namespace VkToGpt;

final class VkClient
{
    private const METHODS = [
        'users.get' => false, 'groups.getById' => false, 'wall.get' => false,
        'wall.getById' => false, 'wall.getComments' => false,
        'stats.get' => true, 'stats.getPostReach' => true,
    ];
    private const WRITES = ['wall.post' => true, 'wall.edit' => true];

    public function __construct(
        private readonly ?string $serviceKey,
        private readonly ?string $userToken,
        private readonly ?\Closure $fetch = null,
        private readonly ?\Closure $sleep = null,
        private readonly ?string $communityToken = null,
    ) {}

    public function call(string $method, array $params = [], ?string $writeTarget = null): mixed
    {
        $isWrite = array_key_exists($method, self::WRITES);
        if (!array_key_exists($method, self::METHODS) && !$isWrite) {
            throw new \RuntimeException('VK method blocked');
        }
        if ($isWrite && !in_array($writeTarget, ['personal', 'community'], true)) {
            throw new \RuntimeException('Write target is required');
        }
        $token = match (true) {
            $isWrite && $method === 'wall.post' && $writeTarget === 'community' => $this->communityToken,
            $isWrite => $this->userToken,
            self::METHODS[$method] => $this->userToken,
            default => $this->serviceKey ?: $this->userToken,
        };
        if (!$token) {
            $required = match (true) {
                $isWrite && $method === 'wall.post' && $writeTarget === 'community' => 'VK_COMMUNITY_TOKEN',
                $isWrite || self::METHODS[$method] => 'VK_USER_TOKEN',
                default => 'VK_SERVICE_KEY or VK_USER_TOKEN',
            };
            throw new \RuntimeException($required.' is not configured');
        }
        unset($params['access_token'], $params['v'], $params['method']);
        $body = http_build_query(array_filter($params, static fn ($v) => $v !== null) + ['access_token' => $token, 'v' => '5.199']);
        $maxAttempts = $isWrite ? 1 : 4;
        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            try {
                [$status, $json] = ($this->fetch ?? self::fetch(...))('https://api.vk.com/method/'.$method, $body);
                $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new \RuntimeException('VK API transport or response failure');
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('VK API HTTP error '.(int) $status);
            }
            if (!is_array($payload)) {
                throw new \RuntimeException('VK API invalid response');
            }
            if (isset($payload['error'])) {
                $code = $payload['error']['error_code'] ?? null;
                if ($code === 6 && !$isWrite && $attempt < 3) {
                    ($this->sleep ?? usleep(...))(500000 * 2 ** $attempt);
                    continue;
                }
                throw new \RuntimeException('VK API error '.(is_int($code) ? $code : 'unknown').' (method unavailable, denied, or failed)');
            }
            if (!array_key_exists('response', $payload)) {
                throw new \RuntimeException('VK API invalid response');
            }
            return $payload['response'];
        }
        throw new \RuntimeException('VK API retry limit exceeded');
    }

    private static function fetch(string $url, string $body): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
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
