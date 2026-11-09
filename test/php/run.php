<?php
declare(strict_types=1);
require __DIR__.'/../../php/ReadOnly.php';
require __DIR__.'/../../php/VkClient.php';
require __DIR__.'/../../php/Security.php';
use VkToGpt\ReadOnlyTools as Tools;
use VkToGpt\VkClient;

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
foreach (['wall.post','messages.send','__proto__','wall.get/../wall.post'] as $method) rejects(fn()=>$client->call($method),'blocked');
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
