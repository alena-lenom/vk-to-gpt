import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {TOOLS} from '../../src/read-only.js';

const phpTools = JSON.parse(readFileSync(new URL('../../php/tools.json', import.meta.url)));
assert.deepEqual(phpTools.map(({name}) => name), TOOLS.map(({name}) => name));
for (const tool of TOOLS) {
  const phpTool = phpTools.find(({name}) => name === tool.name);
  assert.deepEqual(phpTool.inputSchema, tool.inputSchema, `Input schema mismatch for ${tool.name}`);
}
console.log('PHP and Node prototype tool names and input schemas match');
