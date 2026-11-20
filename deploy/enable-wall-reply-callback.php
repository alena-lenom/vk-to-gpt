<?php
declare(strict_types=1);
// This is the only authorized VK write: enable wall_reply_new for one confirmed server.
ini_set('display_errors', '0');
const GROUP_ID = 220996873;
const CALLBACK_URL = 'https://vk.tattoomystery.ru/callback.php';
try {
    $config = require '/var/www/u3641285/data/vk-to-gpt/shared/config.php';
    $token = is_array($config) ? ($config['VK_COMMUNITY_TOKEN'] ?? null) : null;
    $secret = is_array($config) ? ($config['VK_CALLBACK_SECRET'] ?? null) : null;
    if (!is_string($token) || $token === '' || !is_string($secret) || $secret === ''
        || ($config['VK_COMMUNITY_ID'] ?? null) !== '220996873') {
        throw new RuntimeException('Credentials or community binding unavailable');
    }
    $call = static function (string $method, array $parameters) use ($token): array {
        $handle = curl_init('https://api.vk.ru/method/'.$method);
        if ($handle === false) throw new RuntimeException('VK transport unavailable');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters + ['v' => '5.199', 'access_token' => $token]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($handle);
        $http = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($http !== 200 || !is_string($body)) {
            throw new RuntimeException('VK transport failure');
        }
        $decoded = json_decode($body, true, 16);
        if (!is_array($decoded)) throw new RuntimeException('Invalid VK response');
        if (isset($decoded['error'])) {
            $code = $decoded['error']['error_code'] ?? null;
            echo 'vk_callback_error_code='.(is_int($code) ? $code : 'unknown')."\n";
            throw new RuntimeException('VK rejected request');
        }
        return $decoded;
    };
    $servers = $call('groups.getCallbackServers', ['group_id' => GROUP_ID]);
    $matches = array_values(array_filter($servers['response']['items'] ?? [], static fn($item): bool =>
        is_array($item) && ($item['url'] ?? null) === CALLBACK_URL));
    if (count($matches) !== 1 || ($matches[0]['status'] ?? null) !== 'ok') {
        throw new RuntimeException('Callback server is not uniquely confirmed');
    }
    $registeredSecret = $matches[0]['secret_key'] ?? null;
    if (!is_string($registeredSecret) || !hash_equals($secret, $registeredSecret)) {
        throw new RuntimeException('Callback secret differs from registered server');
    }
    echo "callback_server_and_secret=verified\n";
    $id = $matches[0]['id'] ?? null;
    if (!is_int($id) || $id <= 0) throw new RuntimeException('Invalid callback server ID');
    $params = ['group_id' => GROUP_ID, 'server_id' => $id];
    $before = $call('groups.getCallbackSettings', $params);
    $enabled = $before['response']['events']['wall_reply_new'] ?? null;
    if ($enabled !== 1 && $enabled !== true) {
        $result = $call('groups.setCallbackSettings', $params + ['wall_reply_new' => 1]);
        if (($result['response'] ?? null) !== 1) {
            throw new RuntimeException('VK did not acknowledge event setting');
        }
    }
    $after = $call('groups.getCallbackSettings', $params);
    $enabled = $after['response']['events']['wall_reply_new'] ?? null;
    if ($enabled !== 1 && $enabled !== true) {
        throw new RuntimeException('Callback event setting did not persist');
    }
    echo "vk_wall_reply_new_enabled=verified\n";
} catch (Throwable) {
    fwrite(STDERR, "vk_callback_event_activation=failed\n");
    exit(1);
}
