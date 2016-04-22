<?php
//マシンの1台分の設定
//"zoneid": "a53ff3d3-042b-4cbd-ad16-494bb8d33e06",

//ネットワークの作成
//マシンの作成
//グループ別のhostファイルの作成 ansibleが使う
//ファイヤーウォールの設定
//LBの設定 (これはマシンができあがってから）

//sshkeyの作成と保存
/*
$ssh_name = 'test7';
$output = shell_exec("cloudstack-api listSSHKeyPairs --name=" . $ssh_name);
$ssh_key_list = json_decode($output , true);
if(isset($ssh_key_list['count']) && $ssh_key_list['count'])
{
	//存在しているキーファイルなので処理なし
} else {
	//ぞんざいしていないのでキーファイルを作成する
	$output = shell_exec("cloudstack-api createSSHKeyPair --name=" . $ssh_name);
	echo $output;
	$key = json_decode($output, true);
	var_export($key);
	$key = $key['createsshkeypairresponse']['keypair']['privatekey'];
	file_put_contents($ssh_name . '.key', $key);
}


//vpsの作成
$array= [
'serviceofferingid' => 'e01a9f32-55c4-4c0d-9b7c-d49a3ccfd3f6',
'zoneid' => 'a53ff3d3-042b-4cbd-ad16-494bb8d33e06',
'group' => 'gate',
'keypair' => 'mac',
'templateid'=>'ec45fa26-e5d0-45a7-b57d-0c3f8e36ff3b',
'displayname' => 'nekoget'. time(),
'name' => 'nekoget'.time()
];

$createMachine = [
'serviceofferingid',
'zoneid',
'group',
'keypair',
'templateid',
'displayname',
'name'
];

//マシンの作成
/*
$com = '';
foreach($array as $key=>$item){
	$com .= ' --' . $key . '='.'"'.$item . '"';
}

$com = 'cloudstack-api deployVirtualMachine '. $com;
echo $com;

$output = shell_exec($com);
echo $output;

$m = json_decode($output,true);
var_dump($m);


//仮装サーバのネットワーク情報を取得
//$net = "cloudstack-api listVirtualMachines --id={$m['id']} -t nic";
//$output = shell_exec($net);
//var_dump($output);

//publicAddressの作成
*/
$address_name="gate_" . time();
$com = 'cloudstack-api associateIpAddress --zoneid=a53ff3d3-042b-4cbd-ad16-494bb8d33e06';
$output = shell_exec($com);
$m = json_decode($output, true);
var_export($m['associateipaddressresponse']['id']);
$id = $m['associateipaddressresponse']['id'];
$com = 'cloudstack-api createTags --resourceids='.$id.' --resourcetype PublicIpAddress --tags[0].key=cloud-description --tags[0].value='.$address_name;
$output = shell_exec($com);

//ファイヤーウォールの設定
$firewall_list = [] ;
$firewall_list[] = [
	"ipaddressid" => $id,
	"startport" => '80',
	"endport" => '80',
	'protocol' => 'TCP',
	'tag' => 'http'
];
$firewall_list[] = [
	"ipaddressid" => $id,
	"startport" => '443',
	"endport" => '443',
	'protocol' => 'TCP',
	'tag' => 'https'
];
$firewall_list[] = [
	"ipaddressid" => $id,
	"startport" => '2221',
	"endport" => '2221',
	'protocol' => 'TCP',
	'tag' => 'ssh'
];

foreach($firewall_list as $firewall){
$com = 'cloudstack-api createFirewallRule ';
foreach($firewall as $key=>$item){
	if($key !== 'tag'){
		$com .= ' --'. $key.'="'.$item . '"';
	}
}
echo $com;
$output = json_decode(shell_exec($com), true);
var_dump($output);
$id = $output['createfirewallruleresponse']['id'];

//タグ
$com = 'cloudstack-api createTags --resourceids='.$id.' --resourcetype=FirewallRule --tags[0].key=cloud-description --tags[0].value="'.$firewall['tag'].'"';
$output = json_decode(shell_exec($com), true);
var_dump($output);
}


//$network = json_decode(shell_exec('cloudstack-api listPublicIpAddresses'), true);
//var_dump($network);




//ネットワークにタグをつけるのはできなかった。
//ネットワークにはファイヤーウォールの設定が必要
//ポートフォwordingの設定どうする？
//マシンの削除
//$del = 'cloudstack-api destroyVirtualMachinei --id='.$m['id'];
//$output = shell_exec($del);
//var_dump($output);
