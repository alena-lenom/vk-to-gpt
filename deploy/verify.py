"""Verify the deployed transport; no VK calls and no secret output."""
import hashlib, json, pathlib, sys, urllib.request, urllib.error
BASE = 'https://vk.tattoomystery.ru'
EXPECTED_WRITE_TOOLS = ["vk_wall_post", "vk_wall_edit", "vk_wall_create_comment"]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(NoRedirect)
def request(path, body=None, token=None, session=None, origin=False, method=None):
    headers={'Accept':'application/json, text/event-stream','Content-Type':'application/json'}
    if token: headers['Authorization']='Bearer '+token
    if session: headers.update({'Mcp-Session-Id':session,'MCP-Protocol-Version':'2025-06-18'})
    if origin: headers['Origin']=BASE
    req=urllib.request.Request(BASE+path,data=json.dumps(body).encode() if body else None,headers=headers,method=method)
    try: r=opener.open(req,timeout=25)
    except urllib.error.HTTPError as e: r=e
    with r: return r.status,r.headers,r.read(2*1024*1024)
def check(ok,label):
    if not ok: raise RuntimeError(label)
def rpc(body):
    text=body.decode()
    if 'event:' in text or text.lstrip().startswith('data:'):
        text=next(x[5:].strip() for x in text.splitlines() if x.startswith('data:'))
    return json.loads(text)
def main():
    baseline=pathlib.Path(sys.argv[-1])
    status,_,home=request('/')
    check(status==200,'homepage HTTP')
    digest=hashlib.sha256(home).hexdigest()
    if sys.argv[1]=='--baseline':
        baseline.write_text(digest); print('homepage_baseline=ok'); return
    check(digest==baseline.read_text(),'homepage changed')
    token=sys.stdin.read().strip()
    check(len(token)>=32,'private bearer configuration')
    check(request('/mcp')[0]==401,'unauthenticated request')
    wrong_bearer_status=request('/mcp',token='incorrect-test-token')[0]
    check(wrong_bearer_status==401,f'invalid bearer HTTP {wrong_bearer_status}')
    check(request('/mcp',token=token,origin=True)[0]==403,'Origin rejection')
    status,_,body=request('/.well-known/oauth-protected-resource')
    try: resource=json.loads(body)
    except (json.JSONDecodeError,UnicodeDecodeError): raise RuntimeError(f'protected-resource metadata JSON HTTP {status}')
    check(status==200 and resource.get('resource')==BASE and resource.get('authorization_servers')==[BASE],'OAuth protected-resource metadata')
    status,_,body=request('/.well-known/oauth-authorization-server')
    try: metadata=json.loads(body)
    except (json.JSONDecodeError,UnicodeDecodeError): raise RuntimeError(f'authorization-server metadata JSON HTTP {status}')
    check(status==200 and metadata.get('issuer')==BASE and metadata.get('code_challenge_methods_supported')==['S256']
          and metadata.get('authorization_response_iss_parameter_supported') is True
          and metadata.get('registration_endpoint')==BASE+'/oauth/register','OAuth authorization metadata')
    init={'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2025-06-18','capabilities':{},'clientInfo':{'name':'github-deploy-check','version':'1'}}}
    status,headers,body=request('/mcp',init,token)
    check(status==200 and 'result' in rpc(body),'initialize')
    session=headers.get('Mcp-Session-Id'); check(bool(session),'session creation')
    try:
        status,_,_=request('/mcp',{'jsonrpc':'2.0','method':'notifications/initialized'},token,session)
        check(status in [200,202,204],'initialized notification')
        status,_,body=request('/mcp',{'jsonrpc':'2.0','id':2,'method':'tools/list'},token,session)
        check(status==200,'tools/list HTTP')
        expected=json.loads((pathlib.Path(__file__).resolve().parents[1]/'php/tools.json').read_text())
        check([t['name'] for t in rpc(body)['result']['tools']]==[t['name'] for t in expected]+EXPECTED_WRITE_TOOLS,'tools/list contract')
    finally: request('/mcp',token=token,session=session,method='DELETE')
    for path in ['/composer.json','/vendor/autoload.php','/php/server.php','/shared/config.php']:
        check(request(path)[0] in [403,404],'private path access')
    print('https_authentication=ok\noauth_discovery=ok\nhttps_initialize_tools_list=ok\nprivate_paths=protected\nhomepage=preserved\nvk_api_calls=none')
if __name__=='__main__':
    try: main()
    except Exception as e:
        print('HTTPS verification failed: '+(str(e) if isinstance(e,RuntimeError) else type(e).__name__),file=sys.stderr)
        sys.exit(1)
