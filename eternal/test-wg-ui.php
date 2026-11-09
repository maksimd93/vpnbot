<?php
// Offline WireGuard navigation tests. Menu builders, network and writes are mocked.
require $argv[1] ?? __DIR__ . '/../app/bot.php';
class WgUiTestBot extends Bot
{
    public string $mode = 'simple';
    public array $screens = [];
    public array $menus = [];
    public array $config = ['wg_instance'=>0, 'amnezia'=>true, 'wg1_amnezia'=>true,
        'awg3'=>false, 'wg1_awg3'=>true, 'dns'=>'1.1.1.1', 'mtu'=>1350, 'subnets'=>['192.168.0.0/16']];
    public function __construct() { $this->input=['callback'=>'','message'=>'','from'=>71,'chat'=>71,'reply'=>0,'message_id'=>8,'callback_id'=>'fixture','username'=>'fixture']; $this->dns='1.1.1.1'; $this->mtu=1350; }
    public function uiMode(): string { return $this->mode; }
    public function getPacConf() { return $this->config; }
    public function setPacConf(array $conf) { throw new RuntimeException('Navigation wrote PAC'); }
    public function ssh($cmd, $service='wg', $wait=true, $log='/dev/null') { throw new RuntimeException('Navigation executed SSH'); }
    public function request($method, $data, $json_header=0) { throw new RuntimeException('Unexpected Telegram'); }
    public function readClients(): array { return [0=>['interface'=>['## name'=>'Phone <&>', 'MTU'=>1300], 'peers'=>[['AllowedIPs'=>'0.0.0.0/0']]], 3=>['# off'=>1,'interface'=>['## name'=>'Tablet'], 'peers'=>[['AllowedIPs'=>'10.0.0.0/8']]]]; }
    public function getName(array $a): string { return $a['## name']; }
    public function uiShow(array $screen) { $this->screens[]=$screen; }
    public function menu($type=false, $arg=false, $return=false) {
        $this->menus[] = [$type,$arg,$this->getInstanceWG()];
        if ($this->mode==='classic') return null;
        [$text,$rows]= $type==='client' ? $this->peerFixture() : $this->rootFixture();
        $this->screens[]=$this->uiWgScreen($text,$rows);
        return null;
    }
    public function rootFixture(): array {
        $commands=['/switchAmnezia 0','/switchAwg3 0 '.($this->getInstanceWG()==='wg1'?1:0),'/resetAmnezia 0',
            '/switchTorrent 0','/switchExchange 0','/subnet 0','/defaultDNS 0','/defaultMTU 0','/switchEndpoint 0',
            '/menu addpeer 0','/menu client 0_0','/menu client 3_0'];
        $rows=array_map(fn($c)=>[['text'=>$c,'callback_data'=>$c]],$commands);
        $rows[]=[['text'=>'<<','callback_data'=>'/menu wg 0'],['text'=>'1','callback_data'=>'/menu wg 0'],['text'=>'>>','callback_data'=>'/menu wg 1']];
        $rows[]=[['text'=>'update','callback_data'=>'/menu wg 0'],['text'=>'back','callback_data'=>'/menu']];
        return ["Menu -> Amnezia 0\n\n<code>Phone recent handshake</code>",$rows];
    }
    public function peerFixture(): array {
        $commands=['/rename 0_0','/timer 0_0','/qr 0','/download 0','/switchClient 0_0','/dns 0_0','/changeAllowedIps 0_0','/changeMTU 0_0','/delete 0_0','/menu wg 0'];
        return ['<pre>PrivateKey = fixture-secret</pre>',array_map(fn($c)=>[['text'=>$c,'callback_data'=>$c]],$commands)];
    }
}
class WgGuardTestBot extends WgUiTestBot
{
    public array $mutations = [];
    public array $peers;
    public function __construct() {
        parent::__construct();
        $this->peers = [0=>parent::readClients(), 1=>parent::readClients()];
        $this->peers[0][0]['interface']['## name'] = 'WG0 phone';
        $this->peers[1][0]['interface']['## name'] = 'WG1 phone';
    }
    public function getXray() { return ['inbounds'=>[]]; }
    public function readClients(): array { return $this->peers[$this->getInstanceWG() === 'wg1' ? 1 : 0]; }
    public function switchClient($client) { $this->mutations[] = ['switchClient', (int) $client, $this->getInstanceWG()]; }
    public function switchAwg3($page=0) { $this->mutations[] = ['switchAwg3', (int) $page, $this->getInstanceWG()]; }
}
class WgFirewallTestBot extends WgUiTestBot
{
    public array $sshCalls = [];
    public function ssh($cmd, $service='wg', $wait=true, $log='/dev/null') { $this->sshCalls[] = [$cmd,$service]; return ''; }
}
class WgPromptIntegrationTestBot extends WgGuardTestBot
{
    public array $requests = [];
    public function request($method, $data, $json_header=0) { $this->requests[] = [$method,$data]; return ['ok'=>true,'result'=>['message_id'=>100]]; }
    public function setDNS($text, $page=0) { $this->mutations[] = ['setDNS', $text, $this->getInstanceWG()]; }
}
function wgCheck($value,string $reason): void { if(!$value)throw new RuntimeException($reason); }
function wgCallbacks(array $rows): array { return array_column(array_merge(...$rows),'callback_data'); }
function wgRaw(string $callback): string { return preg_match('~^/wgdo [01] (.+)$~',$callback,$m) ? base64_decode(strtr($m[1],'-_','+/')) : $callback; }
function wgBound(int $instance, string $callback): string { return '/wgdo '.$instance.' '.rtrim(strtr(base64_encode($callback),'+/','-_'),'='); }
function wgPreview(WgGuardTestBot $bot): string {
    $bot->input['callback'] = wgBound(1, '/switchClient 0_0');
    $bot->action();
    wgCheck($bot->mutations === [], 'Wrapped first click only previews');
    $screen=end($bot->screens);
    wgCheck(str_contains($screen[0], 'Сервер 2') && str_contains($screen[0], 'WG1 phone'), 'Preview identifies the bound peer');
    $callback=wgCallbacks($screen[1])[0] ?? '';
    wgCheck(preg_match('~^/confirmui ([a-f0-9]{24})$~D',$callback,$match)===1, 'Preview has confirmation');
    wgCheck(($_SESSION['ui_review'][$match[1]]['wg'] ?? null)===1, 'Confirmation stores explicit WireGuard instance');
    return $callback;
}
$_SESSION=[];
$b=new WgUiTestBot();
$before=$b->config;
foreach (['root','devices','defaults','protocol','routing'] as $part) {
    $b->input['callback']='/wgui '.$part.' 1 0';
    wgCheck($b->uiWgAction(),'Server route handled');
    [$text,$rows]=end($b->screens);
    wgCheck(str_contains($text,'сервер 2') && in_array('/menu',wgCallbacks($rows),true),'Correct server identity and home');
    foreach($rows as $row)foreach($row as $button)wgCheck(strlen($button['callback_data'])<=64,'Telegram callback bound');
    wgCheck(mb_strlen(strip_tags($text))<4096,'Telegram text limit');
}
foreach (['peer','config','access','network'] as $part) {
    $b->input['callback']='/wgui '.$part.' 1 0_0'; $b->uiWgAction();
    [$text,$rows]=end($b->screens);
    wgCheck(str_contains($text,'fixture-secret')===($part==='config'),'Private configuration appears only in connection page');
    wgCheck(in_array('/menu',wgCallbacks($rows),true),'Device page has home');
    foreach($rows as $row)foreach($row as $button)wgCheck(strlen($button['callback_data'])<=64,'Device callback bound');
    if($part==='network')wgCheck(in_array('/changeAllowedIps 0_0',array_map('wgRaw',wgCallbacks($rows)),true),'Original network action retained');
}
$b->input['callback']='/changeWG 1'; $b->uiWgAction();
wgCheck($b->getInstanceWG()==='wg1' && $b->config===$before,'Instance view is read-only');
$b->input['callback']='/wgdo 0 '.rtrim(strtr(base64_encode('/switchClient 0_0'),'+/','-_'),'=');
wgCheck(!$b->uiWgPrepareAction() && $b->input['callback']==='/switchClient 0_0' && $b->getInstanceWG()==='wg','Bound action selects original instance');
foreach (['/wgdo 2 AAA','/wgdo 0 '.base64_encode('/changeTransport Reality'),'/wgdo 1 !!!'] as $invalid) {
    $b->input['callback']=$invalid; wgCheck($b->uiWgPrepareAction(),'Invalid bound command blocked');
}
$b->input['callback']=''; $b->input['reply']=123;
$_SESSION['ui_wg_prompts'][123]=['instance'=>1,'time'=>time()];
wgCheck(!$b->uiWgPrepareAction() && $b->getInstanceWG()==='wg1' && !isset($_SESSION['ui_wg_prompts'][123]),'Reply restores bound instance once');
$_SESSION['ui_wg_prompts'][123]=['instance'=>0,'time'=>time()-301]; $_SESSION['reply'][123]=['callback'=>'mutation'];
wgCheck($b->uiWgPrepareAction() && !isset($_SESSION['reply'][123]),'Expired reply cannot mutate');
$b->input['reply']=0;
foreach (['/defaultDNS 0','/defaultMTU 0','/changeMTU 0_0','/rename 0_0','/timer 0_0','/subnetAdd 0_0_0','/changeIps subnet_0_0','/add_ips','/calc'] as $callback) {
    $b->input['callback']=$callback; wgCheck(is_string($b->uiWgPrompt('@fixture enter value')),'Explained value prompt');
}
$b->input['callback']='/unknown'; wgCheck($b->uiWgPrompt('message')===null,'Unrelated prompt unchanged');
foreach ([['/add','/add_ips','/addSubnets 0','/menu wg 0'],['/changeIps all_0_0','/changeIps subnet_0_0','/changeIps list_0_0','/changeIps proxy_0_0','/menu client 0_0'],['/calc','/subnetAdd 0_0_1','/subnetDelete 0_0_0_1','/menu oc'],['/switchAwg3 0 1 ok','/menu wg 0']] as $commands) {
    $rows=array_map(fn($cb)=>[['text'=>$cb,'callback_data'=>$cb]],$commands);
    $screen=$b->uiWgScreen('Legacy explanation',$rows);
    wgCheck(is_array($screen) && in_array('/menu',wgCallbacks($screen[1]),true),'Nested WG screen has explanation and home');
    $raw=array_map('wgRaw',wgCallbacks($screen[1]));
    foreach($commands as $command)if(!str_starts_with($command,'/menu wg')&&!str_starts_with($command,'/menu client')&&$command!=='/menu oc')wgCheck(in_array($command,$raw,true),'Nested action preserved');
    if(in_array('/menu oc',$commands,true))wgCheck(in_array('/serviceui oc settings',$raw,true),'OpenConnect subnet returns to settings');
}
$b->mode='classic'; $b->input['callback']='/wgdo 1 '.rtrim(strtr(base64_encode('/switchClient 0_0'),'+/','-_'),'=');
wgCheck($b->uiWgPrepareAction() && end($b->menus)[0]==='wg','Stale new action opens classic menu instead of mutating');
wgCheck($b->config===$before,'Navigation never changes PAC');

// Exercise the actual dispatcher and review guard across separate request objects.
// Only the final backend mutation is mocked; PAC defaults deliberately point elsewhere.
$_SESSION=[];
$preview=new WgGuardTestBot();
$confirm=wgPreview($preview);
$apply=new WgGuardTestBot();
wgCheck($apply->getInstanceWG()==='wg' && $apply->config['wg_instance']===0,'Fresh request begins with PAC default WG0');
$apply->input['callback']=$confirm; $apply->action();
wgCheck($apply->mutations===[['switchClient',0,'wg1']], 'Confirmed action runs once against bound WG1 despite PAC default WG0');
wgCheck($apply->config['wg_instance']===0,'Confirmation does not rewrite global instance');
$apply->input['callback']=$confirm; $apply->action();
wgCheck(count($apply->mutations)===1,'Confirmation replay cannot toggle peer again');

$stalePreview=new WgGuardTestBot(); $staleConfirm=wgPreview($stalePreview);
$staleApply=new WgGuardTestBot();
$staleApply->peers[1][0]['# off']=1;
$staleApply->input['callback']=$staleConfirm; $staleApply->action();
wgCheck($staleApply->mutations===[] && str_contains(end($staleApply->screens)[0],'успела измениться'), 'Changed bound peer invalidates prior preview');

// A real ForceReply send records scope; a fresh request restores it before dispatch.
$_SESSION=[];
$awg=new WgGuardTestBot(); $awg->input['callback']=wgBound(1,'/switchAwg3 -1 1'); $awg->action();
$awgConfirm=wgCallbacks(end($awg->screens)[1])[0];
wgCheck(str_starts_with($awgConfirm,'/confirmui ') && $awg->mutations===[], 'AWG uses one scoped preview');
$awgApply=new WgGuardTestBot(); $awgApply->input['callback']=$awgConfirm; $awgApply->action();
wgCheck($awgApply->mutations===[['switchAwg3',-1,'wg1']], 'AWG confirm retains instance and negative page');
$awgApply->input['callback']=$awgConfirm; $awgApply->action();
wgCheck(count($awgApply->mutations)===1,'AWG confirmation cannot toggle again on replay');

// A real ForceReply send records scope; a fresh request restores it before dispatch.
$_SESSION=[];
$prompt=new WgPromptIntegrationTestBot();
$prompt->input['callback']=wgBound(1,'/defaultDNS 0'); $prompt->action();
wgCheck(($_SESSION['ui_wg_prompts'][100]['instance'] ?? null)===1 && isset($_SESSION['reply'][100]), 'Production ForceReply binds saved operation to WG1');
$reply=new WgPromptIntegrationTestBot();
$reply->input['reply']=100; $reply->input['message']='9.9.9.9'; $reply->action();
wgCheck($reply->mutations===[['setDNS','9.9.9.9','wg1']], 'Reply mutation restores selected instance across requests');
wgCheck(!isset($_SESSION['ui_wg_prompts'][100])&&!isset($_SESSION['reply'][100]), 'Used reply and binding are both consumed');

// The command transport is mocked: verify both flags and destination independently.
$firewall=new WgFirewallTestBot(); $firewall->wg=1;
$firewall->config += ['exchange'=>1,'blocktorrent'=>0,'wg1_exchange'=>0,'wg1_blocktorrent'=>1];
$firewall->iptablesWG();
wgCheck($firewall->sshCalls===[['iptables -F','wg1'],['bash /block_torrent.sh','wg1']], 'WG1 firewall reads WG1 flags and targets only WG1');
$firewall->sshCalls=[];
$firewall->config['wg1_exchange']=1; $firewall->config['wg1_blocktorrent']=0;
$firewall->config['exchange']=0; $firewall->config['blocktorrent']=1;
$firewall->iptablesWG();
wgCheck($firewall->sshCalls===[['iptables -F','wg1'],['bash /block_exchange.sh','wg1']], 'WG1 independent exchange flag wins over conflicting WG0 values');
$firewall->sshCalls=[]; $firewall->wg=0; $firewall->iptablesWG();
wgCheck($firewall->sshCalls===[['iptables -F','wg'],['bash /block_torrent.sh','wg']], 'WG0 still reads unprefixed flags and targets WG0');

echo "PASS WireGuard grouped screens, scoped actions/replies, stale confirmation guards and instance-specific firewall flags\n";
