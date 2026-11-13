<?php
declare(strict_types=1);

namespace VkToGpt;

final class WriteTools
{
    public static function definitions(): array
    {
        return [
            [
                'name' => 'vk_wall_post',
                'description' => 'Publish a text post to the configured personal page or community wall.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'enum' => ['personal', 'community']],
                        'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16000],
                    ],
                    'required' => ['target', 'message'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'vk_wall_edit',
                'description' => 'Edit the text of a post on the configured personal page or community wall.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'enum' => ['personal', 'community']],
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16000],
                    ],
                    'required' => ['target', 'post_id', 'message'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function resolve(string $name, array $args, ?string $personalOwnerId, ?string $communityId): array
    {
        $definitions = array_column(self::definitions(), null, 'name');
        if (!isset($definitions[$name])) {
            throw new \InvalidArgumentException('Unknown write tool');
        }
        if (array_diff(array_keys($args), array_keys($definitions[$name]['inputSchema']['properties']))) {
            throw new \InvalidArgumentException('Unknown argument');
        }
        $target = $args['target'] ?? null;
        if (!in_array($target, ['personal', 'community'], true)) {
            throw new \InvalidArgumentException('Invalid target');
        }
        $message = $args['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || preg_match_all('/./us', $message, $matches) === false || count($matches[0]) > 16000) {
            throw new \InvalidArgumentException('Invalid message');
        }
        if ($target === 'personal') {
            if (!is_string($personalOwnerId) || !preg_match('/^[1-9][0-9]*$/D', $personalOwnerId)) {
                throw new \InvalidArgumentException('Personal wall is not configured');
            }
            if ((float) $personalOwnerId > 2147483647) {
                throw new \InvalidArgumentException('Invalid personal owner ID');
            }
            $ownerId = (int) $personalOwnerId;
        } else {
            if (!is_string($communityId) || !preg_match('/^[1-9][0-9]*$/D', $communityId)) {
                throw new \InvalidArgumentException('Community wall is not configured');
            }
            if ((float) $communityId > 2147483647) {
                throw new \InvalidArgumentException('Invalid community ID');
            }
            $ownerId = -(int) $communityId;
        }

        if ($name === 'vk_wall_post') {
            $params = ['owner_id' => $ownerId, 'message' => trim($message), 'random_id' => random_int(1, 2147483647)];
            if ($target === 'community') {
                $params['from_group'] = 1;
            }
            return ['wall.post', $params, $target];
        }
        $postId = $args['post_id'] ?? null;
        if (!is_int($postId) || $postId < 1 || $postId > 2147483647) {
            throw new \InvalidArgumentException('Invalid post_id');
        }
        return ['wall.edit', ['owner_id' => $ownerId, 'post_id' => $postId, 'message' => trim($message)], $target];
    }

    public static function names(): array
    {
        return array_column(self::definitions(), 'name');
    }
}
