import { READ_METHODS } from './vk-client.js';

const integer = (value, field, min, max) => {
  if (!Number.isSafeInteger(value) || value < min || value > max) throw new Error('Invalid ' + field);
  return value;
};
const optional = (args, name, min, max) =>
  args[name] === undefined ? undefined : integer(args[name], name, min, max);
const string = (value, field, maxLength) => {
  if (typeof value !== 'string' || !value.trim() || value.length > maxLength) throw new Error('Invalid ' + field);
  return value.trim();
};
const permitted = (params) => Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined));

export const TOOLS = Object.freeze([
  {
    name: 'vk_wall_get', description: 'Read public wall posts; community owner_id is negative',
    inputSchema: { type: 'object', properties: {
      owner_id: { type: 'integer' }, count: { type: 'integer', minimum: 1, maximum: 100 },
      offset: { type: 'integer', minimum: 0 }, filter: { type: 'string', enum: ['all', 'owner', 'others'] },
    }, required: ['owner_id'], additionalProperties: false },
  },
  {
    name: 'vk_wall_get_by_id', description: 'Read specified wall posts by IDs',
    inputSchema: { type: 'object', properties: { posts: { type: 'string' } }, required: ['posts'], additionalProperties: false },
  },
  {
    name: 'vk_wall_get_comments', description: 'Read comments on a wall post',
    inputSchema: { type: 'object', properties: {
      owner_id: { type: 'integer' }, post_id: { type: 'integer' },
      count: { type: 'integer', minimum: 1, maximum: 100 }, offset: { type: 'integer', minimum: 0 },
    }, required: ['owner_id', 'post_id'], additionalProperties: false },
  },
  {
    name: 'vk_users_get', description: 'Read public VK user profile fields',
    inputSchema: { type: 'object', properties: { user_ids: { type: 'string' } }, required: ['user_ids'], additionalProperties: false },
  },
  {
    name: 'vk_groups_get_by_id', description: 'Read VK community information',
    inputSchema: { type: 'object', properties: { group_id: { type: 'integer', minimum: 1 } }, required: ['group_id'], additionalProperties: false },
  },
  {
    name: 'vk_stats_get', description: 'Read available community statistics (may be unavailable for your token)',
    inputSchema: { type: 'object', properties: {
      group_id: { type: 'integer', minimum: 1 },
      timestamp_from: { type: 'integer', minimum: 0 },
      timestamp_to: { type: 'integer', minimum: 0 },
      interval: { type: 'string', enum: ['day', 'week', 'month', 'year', 'all'] },
    }, required: ['group_id'], additionalProperties: false },
  },
  {
    name: 'vk_post_reach', description: 'Read available reach for specified community posts (token dependent)',
    inputSchema: { type: 'object', properties: {
      group_id: { type: 'integer', minimum: 1 }, post_ids: { type: 'string' },
    }, required: ['group_id', 'post_ids'], additionalProperties: false },
  },
]);

const CALLS = Object.freeze({
  vk_wall_get: (a) => ['wall.get', permitted({
    owner_id: integer(a.owner_id, 'owner_id', -2147483647, 2147483647),
    count: optional(a, 'count', 1, 100) ?? 20, offset: optional(a, 'offset', 0, 10000000),
    filter: a.filter === undefined ? undefined : ['all', 'owner', 'others'].includes(a.filter) ? a.filter : (() => { throw new Error('Invalid filter'); })(),
  })],
  vk_wall_get_by_id: (a) => {
    const posts = string(a.posts, 'posts', 2000);
    if (!/^-?\d+_\d+(,-?\d+_\d+)*$/.test(posts)) throw new Error('Invalid posts');
    return ['wall.getById', { posts }];
  },
  vk_wall_get_comments: (a) => ['wall.getComments', permitted({
    owner_id: integer(a.owner_id, 'owner_id', -2147483647, 2147483647),
    post_id: integer(a.post_id, 'post_id', 1, 2147483647),
    count: optional(a, 'count', 1, 100) ?? 20, offset: optional(a, 'offset', 0, 10000000),
  })],
  vk_users_get: (a) => {
    const user_ids = string(a.user_ids, 'user_ids', 500);
    if (!/^\d+(,\d+)*$/.test(user_ids)) throw new Error('Invalid user_ids');
    return ['users.get', { user_ids }];
  },
  vk_groups_get_by_id: (a) => ['groups.getById', { group_ids: String(integer(a.group_id, 'group_id', 1, 2147483647)) }],
  vk_stats_get: (a) => {
    const from = optional(a, 'timestamp_from', 0, 4102444800);
    const to = optional(a, 'timestamp_to', 0, 4102444800);
    if (from !== undefined && to !== undefined && from > to) throw new Error('Invalid period');
    const interval = a.interval ?? 'day';
    if (!['day', 'week', 'month', 'year', 'all'].includes(interval)) throw new Error('Invalid interval');
    return ['stats.get', permitted({
      group_id: integer(a.group_id, 'group_id', 1, 2147483647),
      timestamp_from: from, timestamp_to: to, interval,
    })];
  },
  vk_post_reach: (a) => {
    const post_ids = string(a.post_ids, 'post_ids', 1000);
    if (!/^\d+(,\d+)*$/.test(post_ids)) throw new Error('Invalid post_ids');
    const group = integer(a.group_id, 'group_id', 1, 2147483647);
    return ['stats.getPostReach', { owner_id: -group, post_ids }];
  },
});

export function resolveTool(name, args = {}) {
  if (typeof name !== 'string' || !Object.hasOwn(CALLS, name)) throw new Error('Read-only policy: MCP tool blocked');
  if (!args || typeof args !== 'object' || Array.isArray(args)) throw new Error('Invalid arguments');
  const allowedKeys = TOOLS.find((tool) => tool.name === name).inputSchema.properties;
  if (Object.keys(args).some((key) => !Object.hasOwn(allowedKeys, key))) throw new Error('Unknown argument');
  const [method, params] = CALLS[name](args);
  if (!Object.hasOwn(READ_METHODS, method)) throw new Error('Read-only policy: VK method blocked');
  return { method, params };
}

export async function callTool(client, name, args) {
  const { method, params } = resolveTool(name, args);
  return client.call(method, params);
}
