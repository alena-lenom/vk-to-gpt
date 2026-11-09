"""Local endpoint smoke test; fake MCP bearer, no VK credentials/network calls."""
import json
import os
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = pathlib.Path(__file__).resolve().parents[2]

def request(base, body=None, bearer=None, session=None):
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}
    if bearer is not None:
        headers['Authorization'] = 'Bearer ' + bearer
    if session:
        headers['Mcp-Session-Id'] = session
        headers['MCP-Protocol-Version'] = '2025-06-18'
    req = urllib.request.Request(base, data=json.dumps(body).encode() if body else None, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=5) as response:
            return response.status, response.headers, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read().decode()

with tempfile.TemporaryDirectory(prefix='vk-mcp-') as sessions:
    for configured in [None, 'invalid token', 'local-test-only']:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = {'PATH': os.environ['PATH'], 'MCP_SESSION_DIR': sessions, 'MCP_ALLOWED_HOST': '127.0.0.1'}
        if configured is not None:
            env['MCP_BEARER_TOKEN'] = configured
        process = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            base = f'http://127.0.0.1:{port}/mcp.php'
            for _ in range(50):
                try:
                    result = request(base)
                    break
                except (OSError, urllib.error.URLError):
                    if process.poll() is not None:
                        raise RuntimeError('PHP HTTP server could not start')
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP HTTP server did not become ready')
            assert result[0] == 401, result
            assert request(base, bearer='wrong')[0] == 401
            if configured != 'local-test-only':
                assert request(base, bearer='local-test-only')[0] == 401
                print('HTTP: absent/invalid configured bearer rejected' if configured else 'HTTP: absent configuration rejected')
                continue
            print('HTTP: missing/wrong request bearer rejected (401)')
            init = {'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2025-06-18','capabilities':{},'clientInfo':{'name':'local-smoke','version':'1'}}}
            status, headers, body = request(base, init, 'local-test-only')
            assert status == 200, (status, body)
            session = headers.get('Mcp-Session-Id')
            assert session, 'SDK did not create session'
            status, _, body = request(base, {'jsonrpc':'2.0','method':'notifications/initialized'}, 'local-test-only', session)
            assert status in [200,202,204], (status,body)
            status, _, body = request(base, {'jsonrpc':'2.0','id':2,'method':'tools/list'}, 'local-test-only', session)
            assert status == 200, (status,body)
            if body.lstrip().startswith('data:') or 'event:' in body:
                body = next(line[5:].strip() for line in body.splitlines() if line.startswith('data:'))
            tools = json.loads(body)['result']['tools']
            expected = json.loads((root / 'php/tools.json').read_text())
            assert [t['name'] for t in tools] == [t['name'] for t in expected]
            assert all(t['annotations']['readOnlyHint'] for t in tools)
            print('HTTP: initialize + tools/list returned seven read-only tools')
        finally:
            process.terminate()
            process.wait(timeout=5)
