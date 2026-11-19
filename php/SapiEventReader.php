<?php
declare(strict_types=1);

namespace VkToGpt;

final class SapiEventSourceException extends \RuntimeException {}

/** Read SAPI's existing normalized Callback records, never receive or persist VK events. */
final class SapiEventReader
{
    public static function read(?string $directory, array $query): array
    {
        $group = $query['group_id'] ?? null;
        $limit = $query['limit'] ?? 20;
        $since = $query['since'] ?? 0;
        $post = $query['post_id'] ?? null;
        if (!is_int($group) || $group <= 0 || !is_int($limit) || $limit < 1 || $limit > 50
            || !is_int($since) || $since < 0 || ($post !== null && (!is_int($post) || $post <= 0))) {
            throw new \InvalidArgumentException('Invalid SAPI event query');
        }
        $basis = ['source' => 'SAPI persisted VK wall_reply_new', 'group_id' => $group,
            'delivery_completeness' => 'NOT_CLAIMED', 'historical_comment_coverage' => 'NOT_CLAIMED'];
        if (!is_string($directory) || trim($directory) === '') {
            return $basis + ['status' => 'NOT_CONFIGURED', 'observed_matching_events' => null, 'items' => []];
        }
        $root = realpath($directory);
        $public = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($root === false || !is_dir($root) || !is_readable($root) || is_link($directory)
            || ($public !== false && str_starts_with($root.'/', $public.'/'))) {
            return $basis + ['status' => 'UNAVAILABLE', 'observed_matching_events' => null, 'items' => []];
        }
        $files = glob($root.'/*.json');
        if ($files === false || count($files) > 2000) {
            return $basis + ['status' => 'UNAVAILABLE', 'observed_matching_events' => null, 'items' => []];
        }
        $events = [];
        foreach ($files as $file) {
            if (!preg_match('/^(?:event|hash)-[0-9a-f]{64}\.json$/D', basename($file))) {
                continue;
            }
            $real = realpath($file);
            if ($real === false || is_link($file) || !str_starts_with($real, $root.'/')
                || !is_file($real) || !is_readable($real)
                || filesize($real) === false || filesize($real) > 16384) {
                throw new SapiEventSourceException('Invalid SAPI event source');
            }
            $raw = file_get_contents($real);
            if (!is_string($raw)) {
                throw new SapiEventSourceException('Invalid SAPI event source');
            }
            try {
                $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new SapiEventSourceException('Invalid SAPI event source');
            }
            if (!is_array($event) || ($event['event_type'] ?? null) !== 'wall_reply_new'
                || ($event['processing_status'] ?? null) !== 'ACCEPTED') {
                continue;
            }
            $reply = $event['interaction'] ?? null;
            if (($event['platform'] ?? null) !== 'VK'
                || ($event['source']['type'] ?? null) !== 'community'
                || ($event['source']['group_id'] ?? null) !== $group
                || !is_array($reply) || ($reply['post_owner_id'] ?? null) !== -$group
                || !is_int($reply['comment_id'] ?? null) || $reply['comment_id'] <= 0
                || !is_int($reply['post_id'] ?? null) || $reply['post_id'] <= 0
                || !is_string($reply['text'] ?? null)
                || !is_string($event['received_at'] ?? null)) {
                throw new SapiEventSourceException('Invalid SAPI event provenance');
            }
            $time = strtotime($event['received_at']);
            if ($time === false) {
                throw new SapiEventSourceException('Invalid SAPI event time');
            }
            if ($time < $since || ($post !== null && $reply['post_id'] !== $post)) {
                continue;
            }
            $fullText = $reply['text'];
            $excerpt = substr($fullText, 0, 2048);
            while ($excerpt !== '' && preg_match('//u', $excerpt) !== 1) {
                $excerpt = substr($excerpt, 0, -1);
            }
            $events[] = ['received_at' => $event['received_at'],
                'comment_id' => $reply['comment_id'], 'post_id' => $reply['post_id'],
                'post_owner_id' => $reply['post_owner_id'],
                'author_id' => is_int($reply['author_id'] ?? null) ? $reply['author_id'] : null,
                'date' => is_int($reply['date'] ?? null) ? $reply['date'] : null,
                'text_excerpt' => $excerpt, 'text_truncated' => strlen($excerpt) !== strlen($fullText),
                '_time' => $time];
        }
        usort($events, static fn(array $a, array $b): int =>
            [$b['_time'], $b['comment_id']] <=> [$a['_time'], $a['comment_id']]);
        $total = count($events);
        $items = array_slice($events, 0, $limit);
        foreach ($items as &$item) unset($item['_time']);
        unset($item);
        return $basis + ['status' => 'AVAILABLE', 'observed_matching_events' => $total,
            'returned_events' => count($items), 'more_observed_events' => $total > $limit,
            'post_id_filter' => $post, 'since_unix' => $since, 'items' => $items];
    }
}
