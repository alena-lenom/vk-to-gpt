import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveTool, TOOLS, callTool } from '../src/read-only.js';
import { VkClient, READ_METHODS, VK_API_VERSION } from '../src/vk-client.js';

test('only intended read tools are exposed and resolved', () => {
  assert.equal(TOOLS.length, 7);
  for (const tool of TOOLS) {
    const properties = tool.inputSchema.properties;
    assert.equal(Object.hasOwn(properties, 'access_token'), false);
    assert.equal(Object.hasOwn(properties, 'method'), false);
  }
  for (const method of ['wall.post', 'wall.edit', 'wall.delete', 'messages.send', 'groups.join', 'wall.createComment', 'photos.saveWallPhoto']) {
    assert.equal(Object.hasOwn(READ_METHODS, method), false);
  }
  assert.throws(() => resolveTool('vk_wall_post', { message: 'test' }), /blocked/);
  assert.throws(() => resolveTool('__proto__', {}), /blocked/);
});

test('parameters cannot override VK credentials or invoke extra methods', () => {
  assert.throws(() => resolveTool('vk_wall_get', { owner_id: -1, access_token: 'attacker' }), /Unknown argument/);
  assert.throws(() => resolveTool('vk_stats_get', { group_id: 2, timestamp_from: 20, timestamp_to: 10 }), /period/);
  assert.throws(() => resolveTool('vk_wall_get', { owner_id: '-1' }), /Invalid owner_id/);
  assert.throws(() => resolveTool('vk_wall_get_by_id', { posts: '1_2;wall.post' }), /Invalid posts/);
  assert.deepEqual(resolveTool('vk_post_reach', { group_id: 23, post_ids: '4,5' }), {
    method: 'stats.getPostReach', params: { owner_id: -23, post_ids: '4,5' },
  });
});

test('client rejects forbidden methods without contacting network', async () => {
  let calls = 0;
  const client = new VkClient({
    serviceKey: 'secret',
    fetcher: async () => { calls++; throw new Error('network called'); },
  });
  for (const method of ['wall.post', 'messages.send', 'wall.delete', '__proto__']) {
    await assert.rejects(client.call(method), /blocked/);
  }
  assert.equal(calls, 0);
});

test('VK requests fix version/token; filters injection; paginate by offset', async () => {
  let details;
  const client = new VkClient({
    serviceKey: 'secret-key',
    fetcher: async (url, options) => {
      details = { url, method: options.method, body: new URLSearchParams(options.body) };
      return { ok: true, json: async () => ({ response: { count: 1, items: [{ id: 10 }] } }) };
    },
  });
  const result = await callTool(client, 'vk_wall_get', { owner_id: -10, count: 1, offset: 10 });
  assert.equal(result.items[0].id, 10);
  assert.equal(details.url, 'https://api.vk.com/method/wall.get');
  assert.equal(details.method, 'POST');
  assert.equal(details.body.get('access_token'), 'secret-key');
  assert.equal(details.body.get('v'), VK_API_VERSION);
  assert.equal(details.body.get('offset'), '10');
  assert.equal(details.body.get('owner_id'), '-10');
  await client.call('wall.get', { owner_id: -1, access_token: 'injected', v: '0' });
  assert.equal(details.body.get('access_token'), 'secret-key');
  assert.equal(details.body.get('v'), VK_API_VERSION);
});

test('statistics require a separate authorized user token', async () => {
  const client = new VkClient({ serviceKey: 'public', userToken: undefined, fetcher: async () => { throw Error('Unexpected network'); } });
  // Explicitly override the ambient environment if set.
  client.userToken = null;
  await assert.rejects(client.call('stats.get', { group_id: 1 }), /VK_USER_TOKEN/);
});

test('VK rate limit retries and error responses do not expose token', async () => {
  let count = 0;
  const client = new VkClient({
    serviceKey: 'sensitive',
    sleepFn: async () => {},
    fetcher: async () => {
      count++;
      return { ok: true, json: async () => count === 1
        ? { error: { error_code: 6, error_msg: 'rate limited', request_params: [{ key: 'access_token', value: 'sensitive' }] } }
        : { response: { count: 0, items: [] } } };
    },
  });
  assert.deepEqual(await client.call('wall.get', { owner_id: -1 }), { count: 0, items: [] });
  assert.equal(count, 2);

  const broken = new VkClient({ serviceKey: 'sensitive', fetcher: async () => ({
    ok: true, json: async () => ({ error: { error_code: 1051, error_msg: 'unsupported', request_params: [{ key: 'access_token', value: 'sensitive' }] } }),
  }) });
  await assert.rejects(broken.call('wall.get', { owner_id: -1 }), (error) => {
    assert.match(error.message, /1051/);
    assert.equal(error.message.includes('sensitive'), false);
    return true;
  });
});
