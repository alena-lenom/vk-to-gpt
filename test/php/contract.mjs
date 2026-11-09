import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {TOOLS} from '../../src/read-only.js';
assert.deepEqual(JSON.parse(readFileSync(new URL('../../php/tools.json', import.meta.url))), TOOLS);
console.log('PHP tool discovery schemas match all seven Node definitions');
