<?php
declare(strict_types=1);
require __DIR__.'/../../php/ReadOnly.php';
require __DIR__.'/../../php/WriteTools.php';
require __DIR__.'/../../php/VkClient.php';
require __DIR__.'/../../php/Security.php';
use VkToGpt\ReadOnlyTools as Tools;
use VkToGpt\VkClient;
use VkToGpt\WriteTools;

$n = 0;
function check(bool $ok): void { global $n; ++$n; if (!$ok) throw new RuntimeException('Check '.$n.' failed'); }
function rejects(Closure $run, string $text): void {
    try { $run(); } catch (Throwable $e) { check(str_contains($e->getMessage(), $text)); return; }
    throw new RuntimeException('Expected rejection: '.$text);
}
foreach ([false, '', ' ', "bad\ntoken"] as $secret) check(!VkToGpt\authorized($secret, 'Bearer fake'));
foreach ([null, '', 'Basic fake', 'Bearer wrong', 'Bearer fake extra', 'Bearer fake,fake'] as $header) check(!VkToGpt\authorized('fake', $header));
check(VkToGpt\authorized('fake', 'bearer fake'));
check(count(Tools::definitions()) === 7);
$cases = [
    ['vk_wall_get', ['owner_id'=>-1], ['wall.get', ['owner_id'=>-1,'count'=>20]]],
    ['vk_wall_get_by_id', ['posts'=>' -1_2,3_4 '], ['wall.getById',['posts'=>'-1_2,3_4']]],
    ['vk_wall_get_comments', ['owner_id'=>-1,'post_id'=>2,'offset'=>0], ['wall.getComments',['owner_id'=>-1,'count'=>20,'offset'=>0,'post_id'=>2]]],
    ['vk_users_get', ['user_ids'=>'1,2'], ['users.get',['user_ids'=>'1,2']]],
    ['vk_groups_get_by_id', ['group_id'=>2], ['groups.getById',['group_ids'=>'2']]],
    ['vk_stats_get', ['group_id'=>2], ['stats.get',['group_id'=>2,'interval'=>'day']]],
    ['vk_post_reach', ['group_id'=>2,'post_ids'=>'3,4'], ['stats.getPostReach',['owner_id'=>-2,'post_ids'=>'3,4']]],
];
foreach ($cases as [$name,$args,$expected]) check(Tools::resolve($name,$args) === $expected);
foreach ([['owner_id'=>'1'],['owner_id'=>1.0],['owner_id'=>1,'count'=>101],['owner_id'=>1,'count'=>null],['owner_id'=>1,'filter'=>null],['owner_id'=>1,'access_token'=>'x'],['owner_id'=>1,'offset'=>10000001]] as $args) rejects(fn()=>Tools::resolve('vk_wall_get',$args), '');
rejects(fn()=>Tools::resolve('__proto__',[]),'blocked');
rejects(fn()=>Tools::resolve('vk_wall_post',[]),'blocked');
rejects(fn()=>Tools::resolve('vk_stats_get',['group_id'=>1,'timestamp_from'=>2,'timestamp_to'=>1]),'period');
rejects(fn()=>Tools::resolve('vk_wall_get_by_id',['posts'=>'1_2;wall.post']),'posts');
check(count(WriteTools::definitions()) === 2);
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
$writeClient->call('wall.edit',['owner_id'=>123,'post_id'=>9,'message'=>'Edit'],'personal');
check($writeRequests[1][1]['access_token']==='user-token' && $writeRequests[1][1]['owner_id']==='123');
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
check($client->call('wall.get',['owner_id'=>-1,'offset'=>10,'access_token'=>'injected','v'=>'0','method'=>'wall.post']) === ['items'=>[]]);
check(count($requests)===2 && $waits===[500000]);
check($requests[0]===['https://api.vk.com/method/wall.get',['owner_id'=>'-1','offset'=>'10','access_token'=>'fake-service','v'=>'5.199']]);
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
echo "PHP: $n checks passed (mock VK only)\n";
