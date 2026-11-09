<?php
// Offline presentation checks. Menu reads use fixtures; no network, Telegram or writes.
require $argv[1] ?? __DIR__ . '/../app/bot.php';

class ListsUiTestBot extends Bot
{
    public string $mode = 'simple';
    public array $screens = [];
    public array $destinations = [];
    public array $config = ['autoscan'=>true, 'autoscan_timeout'=>1800, 'autodeny'=>false, 'silence'=>1,
        'white'=>['198.51.100.0/24'], 'deny'=>['192.0.2.9'], 'autocleanlogs'=>'2026-11-09 / 7 days',
        'includelist'=>['first.example'=>true, 'second.example'=>false],
        'blocklist'=>['blocked.example'=>true], 'warplist'=>['warp.example'=>false],
        'processlist'=>['firefox.exe'=>true], 'packagelist'=>['org.telegram.messenger'=>true],
        'subnetlist'=>['192.0.2.0/24'=>true], 'rulessetlist'=>['proxy:1h:https://example.org/rules.srs'=>true]];
    public function __construct() { $this->input=['callback'=>'','from'=>71,'chat'=>71,'reply'=>0,'message_id'=>1]; $this->limit=5; }
    public function uiMode(): string { return $this->mode; }
    public function getPacConf() { return $this->config; }
    public function setPacConf(array $conf) { throw new RuntimeException('List view wrote PAC'); }
    public function ssh($cmd, $service='wg', $wait=true, $log='/dev/null') { throw new RuntimeException('List view used SSH'); }
    public function request($method, $data, $json_header=0) { throw new RuntimeException('Unexpected Telegram'); }
    public function i18n(string $text): string { return $text; }
    public function uiShow(array $screen) { $this->screens[]=$screen; }
    public function menu($type=false, $arg=false, $return=false) { $this->destinations[]=[$type,$arg]; return null; }
    public function update($chat, $message_id, $text, $button=false, $reply=false, $mode='HTML') {
        $this->screens[]=$this->uiListsScreen($text,$button);
    }
    public function pacMenu($page=0) {
        [$rows]=$this->listPac('includelist',$page,'pacMenu');
        array_unshift($rows,[['text'=>'PAC','web_app'=>['url'=>'https://example.org/pac?fixture=unchanged']]],
            [['text'=>'community','callback_data'=>'/addCommunityFilter']], [['text'=>'ru-bundle','callback_data'=>'/addLegizFilter']]);
        $this->update(71,1,'<code>https://example.org/pac?fixture=unchanged</code>',$rows);
    }
    public function logs() {
        $rows=[];
        for($i=2;$i<16;$i++) $rows[]=[['text'=>"200 log-$i",'callback_data'=>"/getLog $i"], ['text'=>'clean','callback_data'=>"/clearLog $i"]];
        $rows[]=[['text'=>'all','callback_data'=>'/cleanLog']];
        $rows[]=[['text'=>'auto','callback_data'=>'/autoCleanLogs']];
        $this->update(71,1,'...',$rows);
    }
}
function listsCheck($value,string $message): void { if(!$value)throw new RuntimeException($message); }
function listsCallbacks(array $screen): array { return array_column(array_merge(...$screen[1]),'callback_data'); }
function listsLabels(array $screen): array {
    $result=[]; foreach($screen[1] as $row)foreach($row as $button)if(isset($button['callback_data']))$result[$button['callback_data']]=$button['text'];
    return $result;
}
function listsScreen(ListsUiTestBot $bot,string $callback): array {
    $bot->input['callback']=$callback;
    listsCheck($bot->uiListsAction(),'Navigation was not handled: '.$callback);
    $screen=end($bot->screens);
    listsCheck(is_array($screen) && mb_strlen($screen[0],'UTF-8')<=4096,'Invalid/oversized screen');
    foreach(listsCallbacks($screen) as $cb) listsCheck(strlen($cb)<=64,'Oversized callback: '.$cb);
    listsCheck(in_array('/menu',listsCallbacks($screen),true),'Missing home');
    return $screen;
}
$_SESSION=[];
$b=new ListsUiTestBot(); $before=$b->config;
$root=listsScreen($b,'/listsui pac main');
listsCheck(!in_array('/changeincludelist 0 0',listsCallbacks($root),true),'PAC root overloaded with list actions');
$links=listsScreen($b,'/listsui pac links');
listsCheck(str_contains($links[0],'<code>https://example.org/pac?fixture=unchanged</code>'),'PAC URL changed');
listsCheck($links[1][0][0]['web_app']['url']==='https://example.org/pac?fixture=unchanged','PAC link button changed');
$sources=listsScreen($b,'/listsui pac sources');
listsCheck(in_array('/addLegizFilter',listsCallbacks($sources),true),'Source action lost');
$entries=listsScreen($b,'/listsui pac entries 0'); $labels=listsLabels($entries);
listsCheck(str_contains($labels['/changeincludelist 0 0'],'Выключить: first.example'),'Enabled entry action inverted');
listsCheck(str_contains($labels['/changeincludelist 1 0'],'Включить: second.example'),'Disabled entry action inverted');
listsCheck(in_array('/deleteincludelist 1 0',listsCallbacks($entries),true),'Delete target changed');
listsCheck(!in_array('/deleteAll includelist',listsCallbacks($entries),true),'Bulk deletion must be under tools');
$tools=listsScreen($b,'/listsui tools includelist pac 0');
listsCheck(str_contains($tools[0],'полностью заменяет'),'Import replacement semantics missing');
listsCheck(!array_diff(['/exportList includelist','/importList includelist','/deleteAll includelist'],listsCallbacks($tools)),'Tool callbacks lost');
foreach(['includelist','blocklist','warplist','packagelist','processlist','subnetlist','rulessetlist'] as $type) {
    $screen=listsScreen($b,"/listsui list $type routes 0");
    listsCheck(in_array("/change$type 0 0",listsCallbacks($screen),true),'List entry callback lost');
    listsCheck(in_array('/routes',listsCallbacks($screen),true),'List back target wrong');
    if(in_array($type,['blocklist','warplist']))listsCheck(str_contains($screen[0],'перезапуска'),'Server rule impact missing');
}
$security=listsScreen($b,'/listsui security main');
listsCheck(in_array('/listsui security automation',listsCallbacks($security),true),'Missing automation group');
$automatic=listsScreen($b,'/listsui security automation');
listsCheck(str_contains($automatic[0],'Автоблокировка: выключена'),'Autoban state incorrect');
listsCheck(str_contains(listsLabels($automatic)['/switchBanIp'],'Включить'),'Autoban action incorrect');
listsCheck(str_contains(listsLabels($automatic)['/switchSilence'],'Выключить'),'Next notification state incorrect');
$first=listsScreen($b,'/listsui logs files 0'); $second=listsScreen($b,'/listsui logs files 1');
listsCheck(in_array('/getLog 2',listsCallbacks($first),true) && !in_array('/getLog 8',listsCallbacks($first),true),'Log file pagination failed');
listsCheck(in_array('/getLog 8',listsCallbacks($second),true) && in_array('/clearLog 8',listsCallbacks($second),true),'Log file index changed');
listsCheck(!in_array('/cleanLog',listsCallbacks($first),true),'Bulk clear must be under maintenance');
$maintenance=listsScreen($b,'/listsui logs maintenance');
listsCheck(str_contains($maintenance[0],'не разблокирует IP'),'Log cleanup semantics missing');
$b->input['callback']='/deleteAll blocklist';
$confirm=$b->uiListsScreen('Confirm',[[['text'=>'yes','callback_data'=>'/deleteYes blocklist']],[['text'=>'back','callback_data'=>'/xtlsblock']]]);
listsCheck(in_array('/deleteYes blocklist',listsCallbacks($confirm),true),'Existing deletion confirmation changed');
$b->input['callback']='/denyList 0 1';
$b->denyList(0,1); $white=end($b->screens);
listsCheck(str_contains(listsLabels($white)['/allowIp 198.51.100.0/24 0 1'],'Убрать исключение'),'Whitelist removal mislabeled');
listsCheck(!in_array('/searchLogs 198.51.100.0/24 denyList 0 1',listsCallbacks($white),true),'CIDR detail button would silently do nothing');
$b->denyList(0,0); $deny=end($b->screens);
listsCheck(str_contains(listsLabels($deny)['/allowIp 192.0.2.9 0'],'Разблокировать'),'Unban mislabeled');
$b->input['callback']='/searchLogs 192.0.2.9';
$ipRows=array_map(fn($cb)=>[['text'=>'action','callback_data'=>$cb]],['/denyIp 192.0.2.9 analysisIp 0 0','/whiteIp 192.0.2.9 analysisIp 0 0','/cleanLogs 192.0.2.9','/analysisIp 0']);
$ip=$b->uiListsScreen('192.0.2.9<pre>{"fixture":true}</pre>',$ipRows);
listsCheck(!array_diff(array_column(array_merge(...$ipRows),'callback_data'),listsCallbacks($ip)),'IP action context lost');
listsCheck(str_contains($ip[0],'Очистка журналов лишь удаляет записи'),'IP actions ambiguous');
$b->input['callback']='/searchIp 192.0.2.9'; $raw='<pre>192.0.2.9 GET &lt;fixture&gt;</pre>';
$log=$b->uiListsMessage($raw);
listsCheck(str_contains($log[0],$raw) && in_array('/searchLogs 192.0.2.9',listsCallbacks($log),true),'Diagnostic lines or back link changed');
$long=str_repeat('x',4090); listsCheck($b->uiListsMessage($long)[0]===$long,'Long diagnostic source truncated');
$b->input['callback']=''; listsCheck($b->uiListsMessage("some secret\nmatch")===null,'Unrelated plain text rewritten');
$_SESSION['reply'][101]=['callback'=>'urlcheck']; $b->input['reply']=101;
listsCheck(str_contains($b->uiListsMessage("https://example.org\nmatch")[0],'не доступности'),'PAC match confused with reachability');
foreach(['/autoScanTimeout','/autoCleanLogs','/checkurl','/addips','/reverse 0','/subzones 0','/exclude 0','/importList includelist','/include blocklist','/include white','/include rulessetlist'] as $cb) {
    $b->input['callback']=$cb; listsCheck($b->uiListsPrompt('@user old prompt')!==null,'Missing prompt: '.$cb);
    listsCheck($b->uiListsPrompt('plain credential')===null,'Credential changed by prompt adapter');
}
$b->input['callback']=''; listsCheck($b->uiListsScreen('unrelated',[[['text'=>'x','callback_data'=>'/outside']]])===null,'Outside menu captured');
listsCheck($b->config===$before,'Presentation changed configuration');
$count=count($b->screens); $b->mode='classic'; $b->input['callback']='/listsui pac links';
listsCheck($b->uiListsAction() && count($b->screens)===$count && count($b->destinations)===1,'Classic bypass failed');
echo "lists UI checks passed\n";
