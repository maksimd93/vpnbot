<?php
// Offline export checks using real endpoint builders and fixture-only credentials.
require $argv[1] ?? __DIR__ . '/../app/bot.php';

class ClientExportsTestBot extends Bot
{
    public array $config;
    public array $xrayFixture;
    public array $documents = [];
    public array $messages = [];
    public bool $deliveryOk = true;
    public array $edges = ['203.0.113.22', '203.0.113.23'];
    public int $resolverCalls = 0;
    public function __construct()
    {
        $owner = '00000000-0000-4000-8000-000000000001';
        $exit = '00000000-0000-4000-8000-000000000002';
        $this->ip = '192.0.2.50';
        $this->input = ['from'=>7, 'chat'=>-100123, 'callback'=>'', 'message_id'=>2];
        $this->config = ['domain'=>'eternal.example', 'hysteria_pass'=>'a@b:c &/%', 'transport'=>'xhttp',
            'eternal_direct_443'=>true, 'eternal_cdn'=>['uuid'=>$owner],
            'eternal_funnel'=>['enabled'=>true, 'host'=>'test.example.ts.net', 'port'=>443],
            'grpc_reality'=>['enabled'=>true, 'domain'=>'sni.example', 'service'=>'example', 'publicKey'=>'TEST', 'shortId'=>'12'],
            'eternal_exit'=>['enabled'=>true, 'owner_uuid'=>$owner, 'uuid'=>$exit]];
        $this->xrayFixture = ['inbounds'=>[['streamSettings'=>['network'=>'xhttp', 'xhttpSettings'=>['path'=>'/fixture']],
            'settings'=>['clients'=>[['id'=>$owner, 'email'=>'Fixture owner'], ['id'=>$exit, 'email'=>'Eternal · WARP-выход', 'eternal_exit'=>true]]]]]];
    }
    public function getPacConf() { return $this->config; }
    public function getXray() { return $this->xrayFixture; }
    public function funnelAddresses(string $host): array { $this->resolverCalls++; return $this->edges; }
    public function setPacConf(array $conf) { throw new RuntimeException('Export wrote configuration'); }
    public function restartXray($c, $norestart=false) { throw new RuntimeException('Export restarted Xray'); }
    public function request($method, $data, $json_header=0) { throw new RuntimeException('Export sent real Telegram'); }
    public function sendFile($chat, $file, $caption=false, $to=false) {
        $this->documents[]=[$chat,$file,$caption];
        return ['ok'=>$this->deliveryOk];
    }
    public function send($chat, $text, ?int $to=0, $button=false, $reply=false, $mode='HTML', $disable_notification=false) { $this->messages[]=[$chat,$text]; }
}
function clientExportCheck($condition, string $reason): void { if (!$condition) throw new RuntimeException($reason); }
function clientExportLines(array $export): array { return explode("\n", rtrim($export['content'], "\n")); }

$b = new ClientExportsTestBot();
$initialConfig = $b->config; $initialXray = $b->xrayFixture;
foreach (['auto', 'exit'] as $mode) {
    $profile = $mode === 'auto' ? $b->eternalAutoProfile() : null;
    $sources = $mode === 'auto'
        ? array_column(array_filter($profile['storage']['sources'][0]['nodes'], fn($node)=>$node['kind']==='server'), 'origin')
        : array_values($b->eternalExitSources()['links']);
    $sourceUris = $mode === 'auto' ? array_column($sources, 'raw') : $sources;
    $sourceBodies = array_map(fn($uri)=>explode('#',$uri,2)[0],$sourceUris);
    foreach (['v2rayn', 'karing_android', 'karing_ios'] as $client) {
        $export = $b->eternalClientExport($client,$mode);
        $lines = clientExportLines($export);
        clientExportCheck($export['mime']==='text/plain' && count($lines)===5 && str_ends_with($export['content'],"\n"),'Portable list shape');
        $app = $client==='v2rayn' ? 'v2rayN' : 'Karing';
        clientExportCheck($export['name']===($mode==='exit'?'Eternal-Exit-WARP-':'Eternal-Auto-').$app.'.txt','Filename mismatch');
        clientExportCheck(array_map(fn($uri)=>explode('#',$uri,2)[0],$lines)===$sourceBodies,'Endpoint or transport settings changed');
        foreach ($lines as $uri) {
            $scheme=parse_url($uri,PHP_URL_SCHEME); parse_str(parse_url($uri,PHP_URL_QUERY),$q);
            $name=rawurldecode(parse_url($uri,PHP_URL_FRAGMENT));
            clientExportCheck(str_starts_with($name,$mode==='exit'?'Eternal WARP · ':'Eternal · '),'Mode naming ambiguous');
            clientExportCheck(empty($q['insecure']) && empty($q['allowInsecure']) && !empty($q['sni']),'TLS verification or SNI lost');
            if($scheme==='vless') {
                clientExportCheck(parse_url($uri,PHP_URL_USER)===$b->config[$mode==='exit'?'eternal_exit':'eternal_cdn']['uuid'],'Wrong user UUID');
                clientExportCheck(in_array($q['security'],['tls','reality'],true),'TLS removed');
                if($q['type']==='xhttp') {
                    $extra=json_decode($q['extra'],true,512,JSON_THROW_ON_ERROR);
                    clientExportCheck($q['mode']==='packet-up' && $q['alpn']==='h2' && $extra['xmux']['hKeepAlivePeriod']===15,'XHTTP settings lost');
                    clientExportCheck($extra['xmux']['maxConnections']===($mode==='exit'?0:3),'XMUX mode changed');
                } elseif($q['type']==='grpc') {
                    clientExportCheck($q['security']==='reality' && $q['pbk']==='TEST' && $q['sid']==='12'
                        && $q['sni']==='sni.example' && $q['serviceName']==='example' && $q['mode']==='gun','Reality/gRPC settings lost');
                }
            } else {
                clientExportCheck($mode==='auto' && $scheme==='hysteria2','Hysteria leaked into isolated exit mode');
                clientExportCheck(rawurldecode(parse_url($uri,PHP_URL_USER))===$b->config['hysteria_pass'],'Hy2 password escaped incorrectly');
            }
        }
        clientExportCheck(!preg_match('~privateKey|secretKey|wireguard://|\bDIRECT\b~',$export['content']),'Server WARP key or direct fallback exposed');
        $b->documents=[];
        clientExportCheck($b->sendEternalAdditionalFiles($client,$mode) && count($b->documents)===1,'Sender dispatch');
        [$chat,$file,$caption]=$b->documents[0];
        clientExportCheck($chat===7 && $file->data===$export['content'] && $file->postname===$export['name'],'Private delivery or payload changed');
        clientExportCheck(mb_strlen($caption)<=1024,'Caption exceeds Telegram limit');
    }
    clientExportCheck($b->eternalClientExport('karing_android',$mode)===$b->eternalClientExport('karing_ios',$mode),'Karing platform payload drift');
    $calls=$b->resolverCalls;
    clientExportCheck(count(clientExportLines($b->eternalClientExport('karing_ios',$mode,false)))===3 && $b->resolverCalls===$calls,'Read-only availability resolved DNS');
}
clientExportCheck($b->config===$initialConfig && $b->xrayFixture===$initialXray,'Export changed source state');
$b->deliveryOk=false; $b->documents=[]; $b->messages=[];
clientExportCheck(!$b->sendEternalAdditionalFiles('karing_ios','exit'),'Telegram failure reported as delivery success');
clientExportCheck(count($b->documents)===1 && $b->documents[0][0]===7
    && $b->documents[0][1]->data===$b->eternalClientExport('karing_ios','exit')['content']
    && $b->messages===[],'Failed delivery changed payload or sent a success/status message');
$b->deliveryOk=true;
foreach ([['bad','auto'],['v2rayn','unknown'],['lxbox','exit']] as [$client,$mode]) {
    $calls=$b->resolverCalls; clientExportCheck($b->eternalClientExport($client,$mode)===[] && $b->resolverCalls===$calls,'Invalid export accepted');
}
foreach (['owner-off','owner-missing','gRPC-off','direct-off','funnel-off'] as $case) {
    $b=new ClientExportsTestBot();
    if($case==='owner-off')$b->xrayFixture['inbounds'][0]['settings']['clients'][0]['off']=true;
    if($case==='owner-missing')array_shift($b->xrayFixture['inbounds'][0]['settings']['clients']);
    if($case==='gRPC-off')$b->config['grpc_reality']['enabled']=false;
    if($case==='direct-off')$b->config['eternal_direct_443']=false;
    if($case==='funnel-off')$b->config['eternal_funnel']['enabled']=false;
    foreach(['auto','exit'] as $mode)foreach(['v2rayn','karing_android','karing_ios'] as $client)
        clientExportCheck($b->eternalClientExport($client,$mode)===[],'Owner/endpoint gating failed: '.$case);
}
foreach (['exit-off','exit-user-off','exit-user-missing','exit-marker-missing'] as $case) {
    $b=new ClientExportsTestBot();
    if($case==='exit-off')$b->config['eternal_exit']['enabled']=false;
    if($case==='exit-user-off')$b->xrayFixture['inbounds'][0]['settings']['clients'][1]['off']=true;
    if($case==='exit-user-missing')unset($b->xrayFixture['inbounds'][0]['settings']['clients'][1]);
    if($case==='exit-marker-missing')unset($b->xrayFixture['inbounds'][0]['settings']['clients'][1]['eternal_exit']);
    clientExportCheck($b->eternalClientExport('karing_ios','exit')===[] && $b->eternalClientExport('karing_ios','auto')!==[],'Exit isolation failed: '.$case);
    clientExportCheck(!$b->sendEternalAdditionalFiles('karing_ios','exit') && !$b->documents && $b->messages[0][0]===7,'Unavailable export leaked file');
}
echo "PASS portable client exports, source fidelity, TLS, owner gating, WARP isolation and private delivery\n";
