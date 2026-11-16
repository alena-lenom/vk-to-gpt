<?php
declare(strict_types=1);

namespace VkToGpt;

final class ReadOnlyTools
{
    public static function definitions(): array
    {
        return json_decode(file_get_contents(__DIR__.'/tools.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function resolve(string $name, array $args, ?string $personalOwnerId = null, ?string $communityId = null): array
    {
        $tools = array_column(self::definitions(), null, 'name');
        if (!isset($tools[$name])) {
            throw new \InvalidArgumentException('Read-only policy: MCP tool blocked');
        }
        if (array_diff(array_keys($args), array_keys($tools[$name]['inputSchema']['properties']))) {
            throw new \InvalidArgumentException('Unknown argument');
        }
        $int = static function (string $key, int $min, int $max) use ($args): int {
            $value = $args[$key] ?? null;
            if (!is_int($value) || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('Invalid '.$key);
            }
            return $value;
        };
        $optional = static fn (string $key, int $min, int $max, mixed $default = null) =>
            array_key_exists($key, $args) ? $int($key, $min, $max) : $default;
        $enum = static function (string $key, array $values, mixed $default) use ($args): mixed {
            $value = array_key_exists($key, $args) ? $args[$key] : $default;
            if ($value !== null && !in_array($value, $values, true)) {
                throw new \InvalidArgumentException('Invalid '.$key);
            }
            if ($value === null && array_key_exists($key, $args)) {
                throw new \InvalidArgumentException('Invalid '.$key);
            }
            return $value;
        };
        $ids = static function (string $key, int $length, string $pattern) use ($args): string {
            $value = $args[$key] ?? null;
            if (!is_string($value) || strlen($value) > $length || !preg_match($pattern, trim($value))) {
                throw new \InvalidArgumentException('Invalid '.$key);
            }
            return trim($value);
        };
        $personalOwnerId ??= getenv('VK_PERSONAL_OWNER_ID') ?: null;
        $communityId ??= getenv('VK_COMMUNITY_ID') ?: null;
        $configuredId = static function (?string $value): ?int {
            return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) && (float) $value <= 2147483647
                ? (int) $value
                : null;
        };
        $personalId = $configuredId($personalOwnerId);
        $communityGroupId = $configuredId($communityId);
        $assertOwner = static function (int $ownerId) use ($personalId, $communityGroupId): void {
            if (($personalId === null || $ownerId !== $personalId)
                && ($communityGroupId === null || $ownerId !== -$communityGroupId)) {
                throw new \InvalidArgumentException('Target is not configured');
            }
        };
        $group = static fn () => $int('group_id', 1, 2147483647);
        $wall = static fn () => [
            'owner_id' => $int('owner_id', -2147483647, 2147483647),
            'count' => $optional('count', 1, 100, 20),
            'offset' => $optional('offset', 0, 10000000),
        ];
        [$method, $params] = match ($name) {
            'vk_wall_get' => ['wall.get', $wall() + ['filter' => $enum('filter', ['all', 'owner', 'others'], null)]],
            'vk_wall_get_by_id' => ['wall.getById', ['posts' => $ids('posts', 2000, '/^-?\d+_\d+(,-?\d+_\d+)*$/D')]],
            'vk_wall_get_comments' => ['wall.getComments', $wall() + ['post_id' => $int('post_id', 1, 2147483647)]],
            'vk_users_get' => ['users.get', ['user_ids' => $ids('user_ids', 500, '/^\d+(,\d+)*$/D')]],
            'vk_groups_get_by_id' => ['groups.getById', ['group_ids' => (string) $group()]],
            'vk_post_reach' => ['stats.getPostReach', ['owner_id' => -$group(), 'post_ids' => $ids('post_ids', 1000, '/^\d+(,\d+)*$/D')]],
            'vk_stats_get' => ['stats.get', [
                'group_id' => $group(),
                'timestamp_from' => $optional('timestamp_from', 0, 4102444800),
                'timestamp_to' => $optional('timestamp_to', 0, 4102444800),
                'interval' => $enum('interval', ['day', 'week', 'month', 'year', 'all'], 'day'),
            ]],
        };
        switch ($name) {
            case 'vk_wall_get':
            case 'vk_wall_get_comments':
                $assertOwner($params['owner_id']);
                break;
            case 'vk_wall_get_by_id':
                $postOwners = array_values(array_unique(array_map(
                    static fn (string $post) => (int) explode('_', $post, 2)[0],
                    explode(',', $params['posts']),
                )));
                if (count($postOwners) !== 1) {
                    throw new \InvalidArgumentException('Posts must belong to one configured wall');
                }
                $assertOwner($postOwners[0]);
                break;
            case 'vk_users_get':
                if ($personalId === null || $params['user_ids'] !== (string) $personalId) {
                    throw new \InvalidArgumentException('Target is not configured');
                }
                break;
            case 'vk_groups_get_by_id':
                if ($communityGroupId === null || $params['group_ids'] !== (string) $communityGroupId) {
                    throw new \InvalidArgumentException('Target is not configured');
                }
                break;
            case 'vk_stats_get':
                if ($communityGroupId === null || $params['group_id'] !== $communityGroupId) {
                    throw new \InvalidArgumentException('Target is not configured');
                }
                break;
            case 'vk_post_reach':
                if ($communityGroupId === null || $params['owner_id'] !== -$communityGroupId) {
                    throw new \InvalidArgumentException('Target is not configured');
                }
                break;
        }
        if (isset($params['timestamp_from'], $params['timestamp_to']) && $params['timestamp_from'] > $params['timestamp_to']) {
            throw new \InvalidArgumentException('Invalid period');
        }
        return [$method, array_filter($params, static fn ($value) => $value !== null)];
    }
}
