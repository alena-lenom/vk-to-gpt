#!/usr/bin/env node
// Adapted from bulatko/vk-mcp-server (MIT). See THIRD_PARTY_NOTICES.md.
import { Server } from '@modelcontextprotocol/server';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { VkClient } from './vk-client.js';
import { TOOLS, callTool } from './read-only.js';

export function buildServer(client = new VkClient()) {
  const server = new Server({ name: 'vk-to-gpt', version: '0.1.0' }, { capabilities: { tools: {} } });
  server.setRequestHandler('tools/list', async () => ({ tools: TOOLS.map((tool) => ({
    ...tool, annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true },
  })) }));
  server.setRequestHandler('tools/call', async (request) => {
    try {
      const result = await callTool(client, request.params.name, request.params.arguments ?? {});
      return { content: [{ type: 'text', text: JSON.stringify(result) }] };
    } catch (error) {
      return { isError: true, content: [{ type: 'text', text: JSON.stringify({ error: error.message }) }] };
    }
  });
  return server;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  serveStdio(buildServer);
}
