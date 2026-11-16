import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {TOOLS} from '../../src/read-only.js';

const phpTools = JSON.parse(readFileSync(new URL('../../php/tools.json', import.meta.url)));
assert.deepEqual(phpTools.map(({name}) => name), TOOLS.map(({name}) => name));
for (const tool of TOOLS) {
  const phpTool = phpTools.find(({name}) => name === tool.name);
  assert.deepEqual(phpTool.inputSchema, tool.inputSchema, `Input schema mismatch for ${tool.name}`);
}
const verifier = readFileSync(new URL('../../deploy/verify.py', import.meta.url), 'utf8');
const writeSource = readFileSync(new URL('../../php/WriteTools.php', import.meta.url), 'utf8');
const verifierWriteNames = JSON.parse(verifier.match(/EXPECTED_WRITE_TOOLS = (\\[[^\\n]+\\])/)[1]);
const phpWriteNames = [...writeSource.matchAll(/'name'\\s*=>\\s*'([^']+)'/g)].map(([, name]) => name);
assert.deepEqual(verifierWriteNames, phpWriteNames, 'Deployment verifier write tools must match PHP tools');

console.log('PHP and Node read schemas and deployment tool names match');
