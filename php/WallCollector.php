<?php
declare(strict_types=1);

namespace VkToGpt;

/**
 * Read a bounded, non-persistent wall.get snapshot using SAPI's page-by-offset pattern.
 * Snapshot totals can change during pagination; this is not audience/reach statistics.
 */
final class WallCollector
{
    public static function collect(VkClient $client, array $query): array
    {
        $ownerId = $query['owner_id'];
        $offset = $query['offset'] ?? 0;
        $limit = $query['limit'] ?? 100;
        if (!is_int($ownerId) || $ownerId === 0 || !is_int($offset) || $offset < 0
            || !is_int($limit) || $limit < 1 || $limit > 300) {
            throw new \InvalidArgumentException('Invalid wall collection bounds');
        }
        $filter = $query['filter'] ?? null;
        if ($filter !== null && !in_array($filter, ['all', 'owner', 'others'], true)) {
            throw new \InvalidArgumentException('Invalid wall filter');
        }

        $items = [];
        $seen = [];
        $fetched = 0;
        $pages = 0;
        $reportedTotal = null;
        $lastTotal = null;
        $totalsChanged = false;
        $duplicatePosts = 0;
        $otherOwnerPosts = 0;
        $stoppedShort = false;

        while ($fetched < $limit) {
            $pageSize = min(100, $limit - $fetched);
            $params = ['owner_id' => $ownerId, 'count' => $pageSize, 'offset' => $offset + $fetched];
            if ($filter !== null) {
                $params['filter'] = $filter;
            }
            $response = $client->call('wall.get', $params);
            if (!is_array($response) || !isset($response['count'], $response['items'])
                || !is_int($response['count']) || $response['count'] < 0
                || !is_array($response['items']) || !array_is_list($response['items'])
                || count($response['items']) > $pageSize) {
                throw new \RuntimeException('Invalid wall.get page response');
            }
            $pages++;
            $lastTotal = $response['count'];
            if ($reportedTotal === null) {
                $reportedTotal = $lastTotal;
            } elseif ($reportedTotal !== $lastTotal) {
                $totalsChanged = true;
            }
            $pageCount = count($response['items']);
            foreach ($response['items'] as $post) {
                if (!is_array($post) || !isset($post['id'], $post['owner_id'])
                    || !is_int($post['id']) || $post['id'] <= 0 || !is_int($post['owner_id'])
                    || $post['owner_id'] === 0) {
                    throw new \RuntimeException('Invalid VK post identity');
                }
                $postKey = $post['owner_id'].'_'.$post['id'];
                if (isset($seen[$postKey])) {
                    $duplicatePosts++;
                    continue;
                }
                $seen[$postKey] = true;
                if ($post['owner_id'] !== $ownerId) {
                    $otherOwnerPosts++;
                }
                $counter = static function (array $post, string $key): ?int {
                    $value = $post[$key]['count'] ?? null;
                    return is_int($value) && $value >= 0 ? $value : null;
                };
                $items[] = [
                    'post_key' => $postKey,
                    'owner_id' => $post['owner_id'],
                    'from_id' => is_int($post['from_id'] ?? null) ? $post['from_id'] : null,
                    'date' => is_int($post['date'] ?? null) ? $post['date'] : null,
                    'views' => $counter($post, 'views'),
                    'likes' => $counter($post, 'likes'),
                    'comments' => $counter($post, 'comments'),
                    'reposts' => $counter($post, 'reposts'),
                    'attachments' => is_array($post['attachments'] ?? null) ? count($post['attachments']) : null,
                    'is_repost' => !empty($post['copy_history']),
                ];
            }
            $fetched += $pageCount;
            if ($pageCount === 0 || $offset + $fetched >= $lastTotal) {
                break;
            }
            if ($pageCount < $pageSize) {
                $stoppedShort = true;
                break;
            }
        }

        $hasMore = $offset + $fetched < ($lastTotal ?? 0);
        $complete = $offset === 0 && !$hasMore && !$totalsChanged && $duplicatePosts === 0;
        return [
            'source' => 'VK wall.get',
            'owner_id' => $ownerId,
            'filter' => $filter ?? 'all',
            'collected_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'reported_total' => $reportedTotal,
            'reported_total_changed' => $totalsChanged,
            'offset' => $offset,
            'fetched' => $fetched,
            'unique_posts' => count($items),
            'pages' => $pages,
            'next_offset' => $hasMore && $fetched > 0 ? $offset + $fetched : null,
            'coverage' => $complete ? 'COMPLETE' : 'PARTIAL',
            'stopped_on_short_page' => $stoppedShort,
            'duplicate_posts' => $duplicatePosts,
            'different_owner_posts' => $otherOwnerPosts,
            'metric_scope' => 'post_counters_only_not_audience_reach',
            'items' => $items,
        ];
    }
}
