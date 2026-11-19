<?php
declare(strict_types=1);

// One-shot, server-side, read-only MCP probe. No credentials or post bodies are emitted.
ini_set('display_errors', '0');

function mcpRequest(string $token, string $method, ?array $payload, ?string $session): array
{
    $headers = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ];
    if ($session !== null) {
        $headers[] = 'Mcp-Session-Id: '.$session;
        $headers[] = 'MCP-Protocol-Version: 2025-06-18';
    }
    $receivedSession = null;
    $curl = curl_init('https://vk.tattoomystery.ru/mcp');
    if ($curl === false) {
        throw new RuntimeException('MCP request could not be prepared');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$receivedSession): int {
            if (stripos($line, 'Mcp-Session-Id:') === 0) {
                $receivedSession = trim(substr($line, strlen('Mcp-Session-Id:')));
            }
            return strlen($line);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || !in_array($status, [200, 202, 204], true)) {
        throw new RuntimeException('MCP request failed');
    }
    $decoded = null;
    if ($body !== '') {
        if (preg_match('/^data:\s*(\{.*\})$/m', $body, $match)) {
            $body = $match[1];
        }
        $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    }
    return ['status' => $status, 'payload' => $decoded, 'session' => $receivedSession];
}

function assertProbe(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('MCP read-only contract did not match');
    }
}

try {
    $config = require '/var/www/u3641285/data/vk-to-gpt/shared/config.php';
    assertProbe(is_array($config));
    $token = $config['MCP_BEARER_TOKEN'] ?? null;
    $personalId = $config['VK_PERSONAL_OWNER_ID'] ?? null;
    $groupId = $config['VK_COMMUNITY_ID'] ?? null;
    assertProbe(is_string($token) && strlen($token) >= 32);
    assertProbe(is_string($personalId) && ctype_digit($personalId) && (int)$personalId > 0);
    assertProbe(is_string($groupId) && ctype_digit($groupId) && (int)$groupId > 0);
    $init = mcpRequest($token, 'POST', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [],
            'clientInfo' => ['name' => 'production-read-probe', 'version' => '1']],
    ], null);
    assertProbe($init['status'] === 200 && isset($init['payload']['result']));
    $session = $init['session'];
    assertProbe(is_string($session) && $session !== '');
    try {
        $notice = mcpRequest($token, 'POST', [
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized'
        ], $session);
        assertProbe(in_array($notice['status'], [200, 202, 204], true));
        $list = mcpRequest($token, 'POST', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ], $session);
        $tools = $list['payload']['result']['tools'] ?? null;
        assertProbe(is_array($tools));
        $collector = null;
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === 'vk_wall_collect') {
                $collector = $tool;
                break;
            }
        }
        assertProbe(is_array($collector) && ($collector['annotations']['readOnlyHint'] ?? null) === true);
        echo "mcp_wall_collect_discovered=yes\n";
        foreach (['community' => -(int)$groupId, 'personal' => (int)$personalId] as $label => $ownerId) {
            $call = mcpRequest($token, 'POST', [
                'jsonrpc' => '2.0', 'id' => $label === 'community' ? 3 : 4,
                'method' => 'tools/call',
                'params' => ['name' => 'vk_wall_collect',
                    'arguments' => ['owner_id' => $ownerId, 'limit' => 2, 'offset' => 0]],
            ], $session);
            $result = $call['payload']['result'] ?? null;
            assertProbe(is_array($result) && ($result['isError'] ?? false) !== true);
            $content = $result['content'][0]['text'] ?? null;
            assertProbe(is_string($content));
            $snapshot = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
            assertProbe(is_array($snapshot) && ($snapshot['owner_id'] ?? null) === $ownerId);
            assertProbe(($snapshot['source'] ?? null) === 'VK wall.get'
                && ($snapshot['fetched'] ?? null) === 2
                && ($snapshot['pages'] ?? null) === 1
                && ($snapshot['coverage'] ?? null) === 'PARTIAL'
                && ($snapshot['next_offset'] ?? null) === 2
                && is_int($snapshot['reported_total'] ?? null)
                && $snapshot['reported_total'] >= 2
                && count($snapshot['items'] ?? []) === 2);
            $foreign = 0;
            foreach ($snapshot['items'] as $post) {
                assertProbe(is_array($post) && is_int($post['owner_id'] ?? null)
                    && is_string($post['post_key'] ?? null));
                if ($post['owner_id'] !== $ownerId) $foreign++;
            }
            assertProbe($foreign === $snapshot['different_owner_posts']);
            if (getenv('VK_PROBE_METRICS') === '1') {
                $summary = $snapshot['post_metric_summary'] ?? null;
                $owned = array_values(array_filter($snapshot['items'],
                    static fn (array $post): bool => $post['owner_id'] === $ownerId));
                assertProbe(is_array($summary)
                    && ($summary['scope'] ?? null) === 'matching_owner_posts_only'
                    && ($summary['metric_basis'] ?? null) === 'current_cumulative_post_counters_not_period_activity'
                    && ($summary['coverage'] ?? null) === $snapshot['coverage']
                    && ($summary['owned_posts'] ?? null) === count($owned)
                    && ($summary['excluded_other_owner_posts'] ?? null) === $foreign);
                foreach (['views', 'likes', 'comments', 'reposts'] as $metric) {
                    $record = $summary['metrics'][$metric] ?? null;
                    $known = 0;
                    $sum = 0;
                    foreach ($owned as $post) {
                        if (is_int($post[$metric] ?? null) && $post[$metric] >= 0) {
                            ++$known;
                            $sum += $post[$metric];
                        }
                    }
                    $missing = count($owned) - $known;
                    $status = $missing === 0 ? 'AVAILABLE'
                        : ($known === 0 ? 'UNAVAILABLE' : 'PARTIAL');
                    assertProbe(is_array($record)
                        && ($record['sum_known'] ?? null) === $sum
                        && ($record['available_posts'] ?? null) === $known
                        && ($record['missing_posts'] ?? null) === $missing
                        && ($record['status'] ?? null) === $status);
                }
                printf("%s_post_metric_summary=ok owned=%d excluded=%d\n",
                    $label, count($owned), $foreign);
            }
            printf("%s_wall_collect=ok total=%d fetched=%d coverage=%s other_owner=%d\n",
                $label, $snapshot['reported_total'], $snapshot['fetched'],
                $snapshot['coverage'], $foreign);
        }
    } finally {
        try {
            mcpRequest($token, 'DELETE', null, $session);
        } catch (Throwable) {
            // The MCP session is ephemeral and will expire independently.
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "MCP read-only wall probe failed; sensitive details withheld\n");
    exit(1);
}
