<?php
declare(strict_types=1);
require __DIR__.'/../../php/ReadOnly.php';
require __DIR__.'/../../php/WriteTools.php';
require __DIR__.'/../../php/VkClient.php';
require __DIR__.'/../../php/WallCollector.php';
require __DIR__.'/../../php/Security.php';
use VkToGpt\ReadOnlyTools as Tools;
use VkToGpt\VkClient;
use VkToGpt\WriteTools;

putenv('VK_PERSONAL_OWNER_ID=1');
putenv('VK_COMMUNITY_ID=2');

$n = 0;
function check(bool $ok): void { global $n; ++$n; if (!$ok) throw new RuntimeException('Check '.$n.' failed'); }
function rejects(Closure $run, string $text): void {
    try { $run(); } catch (Throwable $e) { check(str_contains($e->getMessage(), $text)); return; }
    throw new RuntimeException('Expected rejection: '.$text);
}
foreach ([false, '', ' ', "bad\ntoken"] as $secret) check(!VkToGpt\authorized($secret, 'Bearer fake'));
foreach ([null, '', 'Basic fake', 'Bearer wrong', 'Bearer fake extra', 'Bearer fake,fake'] as $header) check(!VkToGpt\authorized('fake', $header));
check(VkToGpt\authorized('fake', 'bearer fake'));
check(count(Tools::definitions()) === 8);
$cases = [
    ['vk_wall_get', ['owner_id'=>-2], ['wall.get', ['owner_id'=>-2,'count'=>20]]],
    ['vk_wall_get', ['owner_id'=>1], ['wall.get', ['owner_id'=>1,'count'=>20]]],
    ['vk_wall_collect', ['owner_id'=>-2,'limit'=>250], ['wall.get', ['owner_id'=>-2,'limit'=>250,'offset'=>0]]],
    ['vk_wall_get_by_id', ['posts'=>' -2_2 '], ['wall.getById',['posts'=>'-2_2']]],
    ['vk_wall_get_comments', ['owner_id'=>-2,'post_id'=>2,'offset'=>0], ['wall.getComments',['owner_id'=>-2,'count'=>20,'offset'=>0,'post_id'=>2]]],
    ['vk_users_get', ['user_ids'=>'1'], ['users.get',['user_ids'=>'1']]],
    ['vk_groups_get_by_id', ['group_id'=>2], ['groups.getById',['group_ids'=>'2']]],
    ['vk_stats_get', ['group_id'=>2], ['stats.get',['group_id'=>2,'interval'=>'day']]],
    ['vk_post_reach', ['group_id'=>2,'post_ids'=>'3,4'], ['stats.getPostReach',['owner_id'=>-2,'post_ids'=>'3,4']]],
];
foreach ($cases as [$name,$args,$expected]) check(Tools::resolve($name,$args) === $expected);
foreach ([['owner_id'=>'1'],['owner_id'=>1.0],['owner_id'=>1,'count'=>101],['owner_id'=>1,'count'=>null],['owner_id'=>1,'filter'=>null],['owner_id'=>1,'access_token'=>'x'],['owner_id'=>1,'offset'=>10000001]] as $args) rejects(fn()=>Tools::resolve('vk_wall_get',$args), '');
rejects(fn()=>Tools::resolve('vk_wall_collect',['owner_id'=>-2,'limit'=>301]),'limit');
rejects(fn()=>Tools::resolve('vk_wall_collect',['owner_id'=>-999]),'configured');
rejects(fn()=>Tools::resolve('__proto__',[]),'blocked');
rejects(fn()=>Tools::resolve('vk_wall_post',[]),'blocked');
rejects(fn()=>Tools::resolve('vk_stats_get',['group_id'=>2,'timestamp_from'=>2,'timestamp_to'=>1]),'period');
rejects(fn()=>Tools::resolve('vk_wall_get_by_id',['posts'=>'1_2;wall.post']),'posts');
rejects(fn()=>Tools::resolve('vk_wall_get',['owner_id'=>-999]),'Target is not configured');
rejects(fn()=>Tools::resolve('vk_users_get',['user_ids'=>'2']),'Target is not configured');
rejects(fn()=>Tools::resolve('vk_wall_get_by_id',['posts'=>'-2_3,1_4']),'one configured wall');
check(count(WriteTools::definitions()) === 3);
check(WriteTools::resolve('vk_wall_post',['target'=>'personal','message'=>' Hello '],'123','456')[0] === 'wall.post');
[$method,$params,$target] = WriteTools::resolve('vk_wall_post',['target'=>'community','message'=>'Text'],'123','456');
check($method==='wall.post' && $target==='community' && $params['owner_id']===-456 && $params['from_group']===1);
[$method,$params,$target] = WriteTools::resolve('vk_wall_edit',['target'=>'personal','post_id'=>7,'message'=>'Edit'],'123','456');
check($method==='wall.edit' && $target==='personal' && $params===['owner_id'=>123,'post_id'=>7,'message'=>'Edit']);
rejects(fn()=>WriteTools::resolve('vk_wall_post',['target'=>'personal','message'=>'x', 'owner_id'=>999],'123','456'),'Unknown argument');
rejects(fn()=>WriteTools::resolve('vk_wall_post',['target'=>'community','message'=>'x'],'123',null),'configured');
rejects(fn()=>WriteTools::resolve('vk_wall_post',['target'=>'personal','message'=>'  '],'123','456'),'message');
rejects(fn()=>WriteTools::resolve('vk_wall_edit',['target'=>'community','post_id'=>'7','message'=>'x'],'123','456'),'post_id');
$writeRequests=[];
$writeClient = new VkClient('service-token','user-token',function($url,$body) use (&$writeRequests) {
    parse_str($body,$params); $writeRequests[]=[$url,$params]; return [200,'{"response":{"post_id":9}}'];
},null,'community-token');
check($writeClient->call('wall.post',['owner_id'=>-456,'message'=>'Text','random_id'=>8,'from_group'=>1],'community')===['post_id'=>9]);
check($writeRequests[0][1]['access_token']==='community-token' && $writeRequests[0][1]['owner_id']==='-456');
[$method,$params,$target] = WriteTools::resolve('vk_wall_create_comment',['target'=>'community','post_id'=>9,'message'=>'Reply','reply_to_comment'=>4],'123','456');
check($method==='wall.createComment' && $target==='community' && $params['owner_id']===-456 && $params['post_id']===9 && $params['reply_to_comment']===4 && strlen($params['guid'])===32);
check(WriteTools::resolve('vk_wall_create_comment',['target'=>'personal','post_id'=>9,'message'=>'Comment'],'123','456')[2]==='personal');
rejects(fn()=>WriteTools::resolve('vk_wall_create_comment',['target'=>'community','post_id'=>9,'message'=>'x','reply_to_comment'=>'4'],'123','456'),'reply_to_comment');
$writeClient->call('wall.createComment',['owner_id'=>-456,'post_id'=>9,'message'=>'Reply','guid'=>'g'],'community');
check($writeRequests[1][1]['access_token']==='community-token' && $writeRequests[1][1]['owner_id']==='-456' && $writeRequests[1][1]['post_id']==='9');
$writeClient->call('wall.edit',['owner_id'=>123,'post_id'=>9,'message'=>'Edit'],'personal');
check($writeRequests[2][1]['access_token']==='user-token' && $writeRequests[2][1]['owner_id']==='123');
$writeAttempts=0;
$writeLimited=new VkClient('service','user',function() use (&$writeAttempts) { ++$writeAttempts; return [200,'{"error":{"error_code":6}}']; },function(){},'community');
rejects(fn()=>$writeLimited->call('wall.post',['owner_id'=>-1,'message'=>'x'],'community'),'error 6');
check($writeAttempts===1);

rejects(fn()=>Tools::resolve('vk_users_get',['user_ids'=>"1\n2"]),'user_ids');
$requests=[]; $waits=[]; $attempt=0;
$client = new VkClient('fake-service','fake-user',function($url,$body) use (&$requests,&$attempt) {
    parse_str($body,$params); $requests[]=[$url,$params];
    return [200,json_encode(++$attempt === 1 ? ['error'=>['error_code'=>6,'request_params'=>['secret']]] : ['response'=>['items'=>[]]])];
},function($delay) use (&$waits) { $waits[]=$delay; });
check($client->call('wall.get',['owner_id'=>-2,'offset'=>10,'access_token'=>'injected','v'=>'0','method'=>'wall.post']) === ['items'=>[]]);
check(count($requests)===2 && $waits===[500000]);
check($requests[0]===['https://api.vk.com/method/wall.get',['owner_id'=>'-2','offset'=>'10','access_token'=>'fake-service','v'=>'5.199']]);
$personalAttempts=[];
$personalClient=new VkClient('fake-service','fake-user',function($url,$body) use (&$personalAttempts) {
    parse_str($body,$params); $personalAttempts[]=$params['access_token'];
    return [200,$params['access_token']==='fake-user' ? '{"error":{"error_code":27}}' : '{"response":{"items":[]}}'];
});
check($personalClient->call('wall.get',['owner_id'=>1])===['items'=>[]]);
check($personalAttempts===['fake-user','fake-service']);
$deniedAttempts=0;
$deniedClient=new VkClient('fake-service','fake-user',function() use (&$deniedAttempts) {
    ++$deniedAttempts; return [200,'{"error":{"error_code":15}}'];
});
rejects(fn()=>$deniedClient->call('wall.get',['owner_id'=>1]),'error 15');
check($deniedAttempts===1);


$client->call('stats.get'); check($requests[2][1]['access_token']==='fake-user');
foreach (['wall.delete','messages.send','__proto__','wall.get/../wall.post'] as $method) rejects(fn()=>$client->call($method),'blocked');
check(count($requests)===3);
rejects(fn()=>(new VkClient('fake',null))->call('stats.get'),'VK_USER_TOKEN');
foreach ([fn()=>[200,'not-json fake-secret'],fn()=>[500,'fake-secret'],fn()=>[200,json_encode(['error'=>['error_code'=>'fake-secret','error_msg'=>'fake-secret','request_params'=>['fake-secret']]])],function(){throw new RuntimeException('fake-secret');}] as $fetch) {
    try { (new VkClient('fake-secret',null,$fetch))->call('wall.get'); throw new LogicException('Expected failure'); }
    catch (RuntimeException $e) { check(!str_contains($e->getMessage(),'fake-secret')); }
}
$attempt=0; $waits=[];
$limited = new VkClient('fake',null,function() use (&$attempt) { ++$attempt; return [200,'{"error":{"error_code":6}}']; },function($d) use (&$waits){$waits[]=$d;});
rejects(fn()=>$limited->call('wall.get'),'error 6'); check($attempt===4 && $waits===[500000,1000000,2000000]);
// Community credentials may read group identity, never substitute for user-only reads.
$communityReads=[];
$communityRead=new VkClient(null,null,function($url,$body) use (&$communityReads) {
    parse_str($body,$params); $communityReads[]=$params;
    return [200,'{"response":{"groups":[{"id":2}]}}'];
},communityToken:'fake-community');
check($communityRead->call('groups.getById',['group_ids'=>'2'])===['groups'=>[['id'=>2]]]);
check($communityReads[0]['access_token']==='fake-community');
rejects(fn()=>$communityRead->call('wall.get',['owner_id'=>-2]),'VK_SERVICE_KEY or VK_USER_TOKEN');
rejects(fn()=>$communityRead->call('stats.get',['group_id'=>2]),'VK_USER_TOKEN');
check(count($communityReads)===1);
$preferred=[];
$preferredFetch=function($url,$body) use (&$preferred) {parse_str($body,$params); $preferred[]=$params['access_token']; return [200,'{"response":[]}'];};
(new VkClient('fake-service','fake-user',$preferredFetch,communityToken:'fake-community'))->call('groups.getById');
(new VkClient(null,'fake-user',$preferredFetch,communityToken:'fake-community'))->call('groups.getById');
check($preferred===['fake-service','fake-user']);
// Token-type error 28 may be retried with an already configured second token.
$typeAttempts=[];
$typeClient=new VkClient('fake-service','fake-user',function($url,$body) use (&$typeAttempts) {
    parse_str($body,$params); $typeAttempts[]=$params['access_token'];
    return [200,$params['access_token']==='fake-service'
        ? '{"error":{"error_code":28,"error_msg":"do not print secret"}}'
        : '{"response":[{"id":3}]}'];
});
check($typeClient->call('wall.getById',['posts'=>'-2_3']) === [['id'=>3]]);
check($typeAttempts === ['fake-service','fake-user']);

// A final VK error keeps only the numeric code, not VK's potentially sensitive payload.
$lastError=new VkClient('fake-service',null,function() {
    return [200,'{"error":{"error_code":1051,"error_msg":"private-secret","request_params":[{"key":"access_token","value":"private-secret"}]}}'];
});
try {
    $lastError->call('wall.getById',['posts'=>'-2_3']);
    throw new LogicException('Expected VK error');
} catch (VkToGpt\VkApiException $e) {
    check($e->vkCode===1051 && $e->getMessage()==='VK API error 1051');
    check(!str_contains($e->getMessage(),'private-secret'));
}

$batchRequests = [];
$batchClient = new VkClient('fake-service', null, function($url, $body) use (&$batchRequests) {
    parse_str($body, $params);
    $batchRequests[] = $params;
    $start = (int)$params['offset'];
    $end = min(235, $start + (int)$params['count']);
    $posts = [];
    for ($i = $start; $i < $end; ++$i) {
        $posts[] = ['id' => $i + 1, 'owner_id' => $i === 1 ? 123 : -2,
            'from_id' => -2, 'date' => 1700000000 + $i,
            'views' => ['count' => $i + 10], 'likes' => ['count' => 0]];
    }
    return [200, json_encode(['response' => ['count' => 235, 'items' => $posts]], JSON_THROW_ON_ERROR)];
});
$full = VkToGpt\WallCollector::collect($batchClient, ['owner_id' => -2, 'limit' => 300]);
check($full['coverage'] === 'COMPLETE' && $full['reported_total'] === 235);
check($full['fetched'] === 235 && $full['pages'] === 3 && $full['next_offset'] === null);
check($full['different_owner_posts'] === 1 && $full['items'][1]['post_key'] === '123_2');
check($full['items'][0]['likes'] === 0 && $full['items'][0]['comments'] === null);
check(array_column($batchRequests, 'count') === ['100', '100', '100']);
check(array_column($batchRequests, 'offset') === ['0', '100', '200']);
check(!array_key_exists('limit', $batchRequests[0]) && $batchRequests[0]['access_token'] === 'fake-service');
$partial = VkToGpt\WallCollector::collect($batchClient, ['owner_id' => -2, 'limit' => 100, 'offset' => 100]);
check($partial['coverage'] === 'PARTIAL' && $partial['next_offset'] === 200);
check($partial['fetched'] === 100 && $partial['items'][0]['post_key'] === '-2_101');
rejects(fn() => VkToGpt\WallCollector::collect($batchClient, ['owner_id' => -2, 'limit' => 301]), 'bounds');
$invalidPage = new VkClient('fake-service', null, fn() => [200,'{"response":{"count":2,"items":[{"id":"bad"}]}}']);
rejects(fn() => VkToGpt\WallCollector::collect($invalidPage, ['owner_id' => -2, 'limit' => 10]), 'identity');
echo "PHP: $n checks passed (mock VK only)\n";
