<?php

/**
 * サーバ一覧をつくるよ
 */
$obj = new createCloud();
$obj->run();

class createCloud
{
	/**
	 * 設定ファイルのパス
	 */
	public $file_path = './project.json';

	public $key_dir = './data/';

	public $publicAddressList = [];

	public $hosts = [];

	public $firewall = [];

	public $zoneId = [];

	public $sshKeyList = [];

	public $config = [];


	public function __construct()
	{
		$this->getConfig();
	}

	public function run()
	{
		//configの取得
		$this->getConfig();
		//ssh keyの作成
		$this->runCreateSSHKey();
		//公開IPアドレス一覧を取得
		$this->getPublicAddressList();
		//公開IPアドレスを作成する
		$this->createPublicAddressAll();
		//gate用仮想サーバを作成する
		$this->createGate();
		//仮想サーバを作成する
		$this->createVirtualMachineAll();
		//ファイヤーウォールの作成
		$this->createFirewallRuleAll();
		//PortForwardinルールの作成
		$this->createPortForwardingRuleAll();
		//LBルールの作成
		$this->createLoadBalancerRulesAll();
		//LBへの配置
		$this->assignToLoadBalancerRuleAll();

		//hostsファイルの作成サーバ用

		//ansible用hostファイルの作成
	}

	/**
	 * ロードバランサーへのvmのアサイン
	 */
	public function assignToLoadBalancerRuleAll()
	{
		$this->checkSetConfig();
		if (!isset($this->config['assignToLoadBalancerRule'])
			|| !count($this->config['assignToLoadBalancerRule'])
		) {
			$this->debug_message('assignToLoadBalancerRuleの設定がありません。');
			return;
		}
		foreach ($this->config['assignToLoadBalancerRule'] as $item) {
			return $this->assignToLoadBalancerRule($item);
		}
	}

	public function assignToLoadBalancerRule($item)
	{
		$ruleId = $this->getLoadBalancerRuleId($item);
		$vmList = $this->getVirtualMachinesByGroup($item['virtualmachineGroup']);
		$vmIds = [];
		foreach($vmList['virtualmachine'] as $item){
			$vmIds[] = $item['id'];
		}
		$vmIds = join(',', $vmIds);

		$option = $this->createOption(['id'=>$ruleId, 'virtualmachineids' => $vmIds]);
		$com = 'cloudstack-api assignToLoadBalancerRule ' . $option;
		$result = $this->runApi($com);
		var_dump($result);
	}

	public function getVirtualMachinesByGroup($group){
		$com = 'cloudstack-api listVirtualMachines --name='.$group.'-';
		$result = $this->runApi($com);
		if(isset($result['listvirtualmachinesresponse']) && count($result['listvirtualmachinesresponse'] > 0))
		{
			return $result['listvirtualmachinesresponse'];
		}
		return [];
	}

	/**
	 * LoadBalancerRuleのidを取得する
	 * @param $item
	 * @return mixed
	 */
	public function getLoadBalancerRuleId($item)
	{
		$optionList['publicipid'] = $this->getPublicAddressIdByName($item['publicAddress']);
		$optionList['name'] = $item['LoadBalancerRule'];
		$option = $this->createOption($optionList);
		$com = 'cloudstack-api listLoadBalancerRules ' . $option;
		$result = $this->runApi($com);
		if (isset($result['listloadbalancerrulesresponse']['loadbalancerrule'][0]['id']))
		{
			return $result['listloadbalancerrulesresponse']['loadbalancerrule'][0]['id'];
		}
	}

	/**
	 *
	 */
	public function createLoadBalancerRulesAll()
	{
		$this->checkSetConfig();
		if (!$this->config['LoadBalancerRules']
			|| !count($this->config['LoadBalancerRules'])
		) {
			$this->debug_message('LoadBalancerRulesの設定がありません。');
			return;
		}
		foreach ($this->config['LoadBalancerRules'] as $item) {
			$this->createLoadBalancerRule($item);
		}
		$this->debug_message('LoadBalancerRulesの作成を完了しました。');
	}

	/**
	 * LoadBalancerRuleの作成
	 * @param $data
	 * @return array
	 */
	public function createLoadBalancerRule($data)
	{
		$data['publicipid'] = $this->getPublicAddressIdByName($data['PublicAddress']);
		unset($data['PublicAddress']);
		$com = "cloudstack-api createLoadBalancerRule " . $this->createOption($data);
		$result = $this->runApi($com);
		return $result;
	}

	/**
	 * portForwardの全登録
	 */
	public function createPortForwardingRuleAll()
	{
		$this->checkSetConfig();
		if (!$this->config['PortForwardingRules']
			|| !count($this->config['PortForwardingRules'])
		) {
			$this->debug_message('PortForwardingRuleが設定されていません。');
			return;
		}
		foreach ($this->config['PortForwardingRules'] as $item) {
			$this->createPortForwardingRule($item);
		}
		$this->debug_message('PortForwardingRulesの設定を完了しました。');

	}

	/**
	 * PortForwardingRuleの作成
	 * @param $item
	 */
	public function createPortForwardingRule($item)
	{
		$tag = $item['tag'];
		unset($item['tag']);
		$vm = $this->getVirtualMachine($item['virtualmachineName']);
		if (!isset($vm['listvirtualmachinesresponse']['virtualmachine'][0]['id'])) {
			$this->debug_message('仮想サーバ[' . $item['virtualmachineName'] . ']が見つかりません。');
			return;
		}
		//vmのidを設定
		$item['virtualmachineid'] = $vm['listvirtualmachinesresponse']['virtualmachine'][0]['id'];
		unset($item['virtualmachineName']);
		//publicAddressを設定
		$item['ipaddressid'] = $this->getPublicAddressIdByName($item['publicAddress']);
		unset($item['publicAddress']);
		$option = $this->createOption($item);
		$com = 'cloudstack-api  createPortForwardingRule ' . $option;
		$result = $this->runApi($com);
		if (isset($result['createportforwardingruleresponse']['id'])) {
			$id = $result['createportforwardingruleresponse']['id'];
			$com = 'cloudstack-api createTags --resourceids=' . $id . ' --resourcetype=PortForwardingRule --tags[0].key=cloud-description --tags[0].value="' . $tag . '"';
			$this->runApi($com);
		}
	}

	/**
	 *  FirewallRuleをすべて作成する
	 */
	public function createFirewallRuleAll()
	{
		$this->checkSetConfig();
		if (!isset($this->config['FirewallRules'])
			|| !count($this->config['FirewallRules'])
		) {
			$this->debug_message('FirewallRulesの設定がありません');
			return;
		}
		foreach ($this->config['FirewallRules'] as $item) {
			$this->createFirewallRule($item);
		}
	}


	/**
	 * 名前からpublicAddressのidを取得する
	 * @param $name
	 * @return null
	 */
	public function getAddressId($name)
	{
		if (isset($this->publicAddressList[$name])) {
			return $this->publicAddressList[$name];
		}
		return null;
	}

	/**
	 * FirewallRuleの
	 * @param $data
	 */
	public function createFirewallRule($data)
	{
		$addressId = $this->getPublicAddressIdByName($data['publicAddress']);
		foreach ($data['rules'] as $i) {
			$tag = $i['tag'];
			unset($i['tag']);
			$i['ipaddressid'] = $addressId;
			//$check = $this->getFirewallRule($i);
			$option = $this->createOption($i);
			$com = 'cloudstack-api createFirewallRule ' . $option;
			$result = $this->runApi($com);
			//すでに登録済みであるかのチェックツールがないため、一旦エラーは無視する。
			if (isset($result['createfirewallruleresponse']['id'])) {
				//タグの登録
				$id = $result['createfirewallruleresponse']['id'];
				$com = 'cloudstack-api createTags --resourceids=' . $id . ' --resourcetype=FirewallRule --tags[0].key=cloud-description --tags[0].value="' . $tag . '"';
				$this->runApi($com);
			}
		}
	}

	/**
	 * FirewallRuleを取得する...
	 * TODO:廃棄予定
	 * @param $option
	 * @return array
	 */
	public function getFirewallRule($option)
	{
		$option = $this->createOption(['ipaddressid' => $option['ipaddressid']]);
		$com = 'cloudstack-api listFirewallRules' . ' ' . $option;
		$result = $this->runApi($com);
		return $result;
	}

	/**
	 * オプションを生成する。
	 *
	 * @param $option
	 * @return string
	 */
	public function createOption($option)
	{
		$text = '';
		foreach ($option as $key => $item) {
			$text .= ' --' . $key . '="' . $item . '"';
		}
		return $text;
	}

	/**
	 * @param $name
	 */
	public function getPublicAddressIdByName($name = '')
	{
		if (!$this->publicAddressList) {
			$this->getPublicAddressList();
			if (!$this->publicAddressList) {
				$this->debug_message('publicAddressが登録されていないため、FirewallRuleの登録ができません。');
				return;
			}
		}
		if (isset($this->publicAddressList[$name]['id'])) {
			return $this->publicAddressList[$name]['id'];
		}
		return null;
	}

	/**
	 * Gateサーバの作成
	 */
	public function createGate()
	{
		$this->checkSetConfig();
		if (!isset($this->config['Gate'])
			|| !isset($this->config['zoneid'])
		) {
			$this->debug_message('Gateの設定がありません');
			return;
		}
		$data = $this->config['Gate'];
		$data['count'] = 1;
		$this->createVirtualMachine($data);
	}

	public function createVirtualMachineAll()
	{
		$this->checkSetConfig();
		if (!isset($this->config['VirtualMachine']) || count($this->config['VirtualMachine']) < 0) {
			$this->debug_message('VirtualMachine作成情報がありません');
			return;
		}

		foreach ($this->config['VirtualMachine'] as $item) {
			$this->createVirtualMachine($item);
		}
		$this->debug_message('VirtualMachine作成を完了しました。');
	}

	/**
	 * 個々のマシンを作成する
	 * @param array $data
	 */
	public function createVirtualMachine($data = [])
	{
		if (!$data) {
			return;
		}
		$con = $data['count'];
		$name_base = $data['group'];

		for ($i = 1; $i <= $con; $i++) {
			$createData = [
				'serviceofferingid' => $data['serviceofferingid'],
				'zoneid' => $this->config['zoneid'],
				'group' => $data['group'],
				'keypair' => $data['keypair'],
				'templateid' => $data['templateid'],
				'displayname' => $name_base . '-' . $i,
				'name' => $name_base . '-' . $i
			];
			//同じマシンがないか確認する
			$checkData = $this->getVirtualMachine($createData['name']);
			if (isset($checkData['listvirtualmachinesresponse']['count'])
				&& $checkData['listvirtualmachinesresponse']['count'] > 0
			) {
				$this->debug_message('仮想サーバ[' . $createData['name'] . ']はすでに存在しています。');
				return;
			}

			$com = '';
			foreach ($createData as $key => $item) {
				$com .= ' --' . $key . '=' . '"' . $item . '"';
			}
			$com = 'cloudstack-api deployVirtualMachine ' . $com;
			$this->runApi($com);
		}
	}

	/**
	 * 個々の仮想サーバを名前で引く
	 * @param string $name
	 * @return array
	 */
	public function getVirtualMachine($name)
	{
		$com = 'cloudstack-api listVirtualMachines --name=' . $name;
		$result = $this->runApi($com);
		return $result;
	}

	/**
	 * sshKeyの作成
	 * @throws Exception
	 */
	public function runCreateSSHKey()
	{
		$this->checkSetConfig();
		$sshKeyList = $this->config['sshKey'];
		foreach ((array)$sshKeyList as $item) {
			$this->createSSHKey($item['name']);
		}
	}

	/**
	 * configが設定されていなかった場合、設定する処理
	 */
	public function checkSetConfig()
	{
		if (!$this->config) {
			$this->getConfig();
		}
	}

	/**
	 * publicAddressの一覧の作成 keyはタグ
	 * @param array $option
	 * @return array
	 */
	public function getPublicAddressList($option = [])
	{
		$command = 'cloudstack-api listPublicIpAddresses';
		$result = $this->runApi($command, $option);
		$list = $result['listpublicipaddressesresponse']['publicipaddress'];
		$addressList = [];
		foreach ((array)$list as $i) {
			$tag = $this->getAddressTag($i);
			if ($tag) {
				$addressList[$tag] = $i;
			}
		}
		$this->publicAddressList = $addressList;
		return $addressList;
	}

	/**
	 * PublicAddressのタグを取得する
	 * @param array $data
	 * @return mixed
	 */
	public function getAddressTag($data = [])
	{
		if (isset($data['tags'][0]['value'])) {
			return $data['tags'][0]['value'];
		}
	}

	//publicIpAddressの作成
	public function createPublicAddressAll()
	{
		$this->checkSetConfig();
		if (!isset($this->config['PublicAddress'])
			|| !count($this->config['PublicAddress'])
		) {
			return;
		}
		foreach ($this->config['PublicAddress'] as $item) {
			$this->createPublicAddress($item);
		}
		//リスト更新
		$this->getPublicAddressList();
	}

	/**
	 * publicAddressを作成する
	 * @param array $data
	 * @return bool|null
	 * @throws Exception
	 */
	public function createPublicAddress($data = [])
	{
		if (!$data || !isset($data['name']) || !isset($data['zoneid'])) {
			return null;
		}
		//存在確認すでに存在しているなら作成しなくていい
		if (isset($this->publicAddressList[$data['name']])) {
			$this->debug_message("publicAddress [" . $data['name'] . ']はすでに存在します');
			return true;
		}
		$com = 'cloudstack-api associateIpAddress --zoneid=' . $data['zoneid'];
		$output = shell_exec($com);
		$m = json_decode($output, true);
		if (!isset($m['associateipaddressresponse']['id'])) {
			throw new Exception('PublicAddress Error: ' . $data['name']);
		}
		$id = $m['associateipaddressresponse']['id'];
		$com = 'cloudstack-api createTags --resourceids='
			. $id
			. ' --resourcetype PublicIpAddress --tags[0].key=cloud-description --tags[0].value='
			. $data['name'];
		shell_exec($com);
		return true;
	}

	//設定ファイルの取得
	public function getConfig()
	{
		$data = file_get_contents($this->file_path);
		$this->config = json_decode($data, true);
	}

	//hostsファイルの作成
	public function createHostFile()
	{

	}

	/**
	 * cloudstack-api コマンドの実行
	 *
	 * @param string $command
	 * @param array $option
	 * @return array
	 */
	public function runApi($command = '', $option = [])
	{
		if (!$command) {
			return [];
		}
		$output = json_decode(shell_exec($command), true);
		return $output;
	}

	/**
	 * sshKeyが登録されていなかった場合、登録しファイル保存する
	 *
	 * @param string $ssh_name
	 * @return bool|void
	 * @throws Exception
	 */
	public function createSSHKey($ssh_name = '')
	{
		$output = shell_exec("cloudstack-api listSSHKeyPairs --name=" . $ssh_name);
		$ssh_key_list = json_decode($output, true);
		if (isset($ssh_key_list['listsshkeypairsresponse']['count'])
			&& $ssh_key_list['listsshkeypairsresponse']['count']
		) {
			$this->debug_message("sshKey[" . $ssh_name . ']はすでに存在します');
			return;
		}
		$output = shell_exec("cloudstack-api createSSHKeyPair --name=" . $ssh_name);
		$key = json_decode($output, true);
		//作成成功時
		if ($key && isset($key['createsshkeypairresponse']['keypair']['privatekey'])) {
			$key = $key['createsshkeypairresponse']['keypair']['privatekey'];
			//ファイルの作成
			file_put_contents($this->key_dir . $ssh_name . '.key', $key);
			return true;
		}
		if (isset($key['createsshkeypairresponse']['errortext'])) {
			throw new Exception($key['createsshkeypairresponse']['errortext']);
		}
	}

	/**
	 * デバックメッセージを出力
	 * TODO: ファイル出力or画面表示で切り替える
	 * @param $text
	 */
	public function debug_message($text)
	{
		echo $text . "\n";
	}
}
