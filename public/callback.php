<?php
declare(strict_types=1);
ini_set('display_errors', '0');

// VK Callback API receiver for the configured community.
// Persists wall_reply_new events in the normalized format read by
// SapiEventReader (vk_sapi_wall_replies). All config comes from the
// protected runtime configuration; never store secrets in this file.

$confirmation = getenv('VK_CALLBACK_CONFIRMATION');
$storageRoot  = getenv('SAPI_STORAGE_ROOT');
if (is_string($storageRoot) && $storageRoot !== '') {
    $rootPath = realpath($storageRoot);
    $file = $rootPath !== false ? $rootPath . '/runtime/vk-callback-confirmation.txt' : '';
    if ($file !== '' && is_file($file) && is_readable($file) && !is_link($file)) {
        $fileConfirmation = trim((string) file_get_contents($file));
        if ($fileConfirmation !== '') {
            $confirmation = $fileConfirmation;
        }
    }
}
$secret       = getenv('VK_CALLBACK_SECRET');
$communityId  = getenv('VK_COMMUNITY_ID');
$eventsDir    = getenv('VK_SAPI_EVENTS_DIR');

header('Content-Type: text/plain; charset=utf-8');

$raw     = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload) || !isset($payload['type'], $payload['group_id'])
    || !is_int($payload['group_id'])) {
    http_response_code(400);
    exit('ok');
}

$configured = is_string($communityId) && preg_match('/^[1-9][0-9]*$/D', $communityId)
    ? (int) $communityId : null;
if ($configured === null || $payload['group_id'] !== $configured) {
    http_response_code($configured === null ? 503 : 403);
    exit($configured === null ? 'unavailable' : 'forbidden');
}

if ($payload['type'] === 'confirmation') {
    if (!is_string($confirmation) || $confirmation === '') {
        http_response_code(503);
        exit('unavailable');
    }
    exit($confirmation); // exactly the confirmation string, nothing else
}

if (is_string($secret) && $secret !== ''
    && (!isset($payload['secret']) || !hash_equals($secret, (string) $payload['secret']))) {
    exit('ok'); // wrong secret: acknowledge, never persist
}

if ($payload['type'] !== 'wall_reply_new' || !isset($payload['event_id'])) {
    exit('ok'); // other enabled event types are acknowledged, not persisted
}

$object = $payload['object'] ?? null;
$commentId   = is_array($object) && is_int($object['id'] ?? null) ? $object['id'] : null;
$postId      = is_array($object) && is_int($object['post_id'] ?? null) ? $object['post_id'] : null;
$postOwnerId = is_array($object) && is_int($object['post_owner_id'] ?? null) ? $object['post_owner_id'] : null;
$fromId      = is_array($object) && is_int($object['from_id'] ?? null) ? $object['from_id'] : null;
$date        = is_array($object) && is_int($object['date'] ?? null) ? $object['date'] : null;
$text        = is_array($object) && is_string($object['text'] ?? null) ? $object['text'] : '';
if ($commentId === null || $postId === null || $postOwnerId !== -$payload['group_id']) {
    exit('ok'); // unexpected provenance: skip, never persist
}

$event = [
    'platform' => 'VK',
    'source' => ['type' => 'community', 'group_id' => $payload['group_id']],
    'event_type' => 'wall_reply_new',
    'processing_status' => 'ACCEPTED',
    'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'interaction' => [
        'comment_id' => $commentId,
        'post_id' => $postId,
        'post_owner_id' => $postOwnerId,
        'author_id' => $fromId,
        'date' => $date,
        'text' => $text,
    ],
];

$root = is_string($eventsDir) ? realpath($eventsDir) : false;
if ($root === false || !is_dir($root) || !is_writable($root)) {
    http_response_code(500); // config gap must stay visible in the VK admin panel
    exit('ok');
}
$name = 'event-' . hash('sha256', $payload['group_id'] . '|' . (string) $payload['event_id']) . '.json';
file_put_contents(
    $root . '/' . $name,
    json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    LOCK_EX
);
exit('ok');
