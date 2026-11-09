// Adapted from bulatko/vk-mcp-server v0.9.0 (MIT). See THIRD_PARTY_NOTICES.md.
export const VK_API_VERSION = '5.199';

// The VK method allowlist is enforced at the API boundary, not only MCP discovery.
export const READ_METHODS = Object.freeze({
  'users.get': 'public',
  'groups.getById': 'public',
  'wall.get': 'public',
  'wall.getById': 'public',
  'wall.getComments': 'public',
  'stats.get': 'stats',
  'stats.getPostReach': 'stats',
});

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const API = 'https://api.vk.com/method';

export class VkClient {
  constructor({ serviceKey = process.env.VK_SERVICE_KEY, userToken = process.env.VK_USER_TOKEN, fetcher = fetch, sleepFn = sleep } = {}) {
    this.serviceKey = serviceKey;
    this.userToken = userToken;
    this.fetcher = fetcher;
    this.sleepFn = sleepFn;
  }

  async call(method, params = {}) {
    if (!Object.hasOwn(READ_METHODS, method)) throw new Error('Read-only policy: VK method blocked');
    const token = READ_METHODS[method] === 'stats' ? this.userToken : (this.serviceKey || this.userToken);
    if (!token) throw new Error(READ_METHODS[method] === 'stats'
      ? 'VK_USER_TOKEN is not configured; statistics require permitted user authorization'
      : 'VK_SERVICE_KEY or VK_USER_TOKEN is not configured');
    const clean = {};
    for (const [key, value] of Object.entries(params)) {
      if (key === 'access_token' || key === 'v' || key === 'method') continue;
      if (value !== undefined && value !== null) clean[key] = value;
    }
    for (let attempt = 0; attempt < 4; attempt++) {
      let response;
      try {
        response = await this.fetcher(API + '/' + method, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ ...clean, access_token: token, v: VK_API_VERSION }),
          signal: AbortSignal.timeout(30000),
        });
      } catch (error) {
        if (error.name === 'TimeoutError' || error.name === 'AbortError') throw new Error('VK API request timed out');
        throw error;
      }
      if (!response.ok) throw new Error('VK API HTTP error ' + response.status);
      const payload = await response.json();
      if (!payload.error) return payload.response;
      const code = payload.error.error_code;
      if (code === 6 && attempt < 3) {
        await this.sleepFn(500 * 2 ** attempt);
        continue;
      }
      // Never expose VK response request_params (may contain the access token).
      throw new Error('VK API error ' + code + ': ' + String(payload.error.error_msg || 'request failed').slice(0, 180));
    }
    throw new Error('VK API retry limit exceeded');
  }
}
