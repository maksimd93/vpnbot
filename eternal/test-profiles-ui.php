<?php
// Exercise grouped profile navigation through actual send/update interception.
require __DIR__ . '/test-admin-ui.php';

class ProfilesUiTestBot extends AdminLegacyOutputTestBot
{
    public string $payload = '<pre><code>vless://fixture-secret@host/?name=Быстрый CDN</code></pre>';
    public array $profileButtons = [];
    public function __construct() {
        parent::__construct();
        $callbacks = ['/resetXrUser 0','/switchXr 0','/timerXr 0','/delxr 0','/renameXrUser 0','/hwidUser 0',
            '/dw 0 s','/dw 0 si','/dw 0 cl','/templateUser v2ray 0','/templateUser sing 0','/templateUser clash 0',
            '/qrXray 0','/qrXray 0_1','/qrXray 0_2','/qrXray 0_3','/funnelMenu 0','/warpMenu 0','/cdnMenu 0','/xray'];
        $this->profileButtons = array_map(fn($cb)=>[['text'=>str_starts_with($cb,'/templateUser ') ? 'type: MyTemplate' : $cb, 'callback_data'=>$cb]],$callbacks);
        $this->profileButtons[] = [['text'=>'sing-box','web_app'=>['url'=>'https://fixture.example/private-key']]];
    }
    public function userXr($i) {
        (new ReflectionMethod(Bot::class, 'update'))->invokeArgs($this,[1,8,$this->payload,$this->profileButtons]);
    }
}
$_SESSION = [];
$b = new ProfilesUiTestBot();
$original = [$b->config,$b->xrayFixture];
$reachable = [];
foreach (['main','links','files','access','templates'] as $page) {
    $b->input['callback'] = '/profileui '.$page.' 0'; $b->action();
    $data = end($b->requests)[1];
    $rows = json_decode($data['reply_markup'],true)['inline_keyboard'];
    $callbacks = adminCallbacks($rows);
    adminCheck(in_array('/menu',$callbacks,true), 'Home available on '.$page);
    adminCheck(mb_strlen(html_entity_decode(strip_tags($data['text'])))<=4096, 'Profile fits Telegram');
    if ($page === 'links') adminCheck(str_contains($data['text'],$b->payload), 'Links preserve payload bytes');
    else adminCheck(!str_contains($data['text'],'fixture-secret'), 'Credentials only in connection page');
    if ($page === 'files') adminCheck(in_array(['url'=>'https://fixture.example/private-key'],array_column(array_merge(...$rows),'web_app'),true), 'Web app link preserved');
    if ($page === 'templates') adminCheck(substr_count(json_encode($rows),'MyTemplate')===3, 'Current template choices visible');
    $reachable=array_merge($reachable,$callbacks);
}
$expected=adminCallbacks($b->profileButtons);
adminCheck(!array_diff(array_diff($expected,['/xray']),$reachable), 'Every original user action reachable');
$b->input['callback']='/userXr 0'; $b->action();
adminCheck(!str_contains(end($b->requests)[1]['text'],'fixture-secret'), 'Page context resets after nested render');
adminCheck([$b->config,$b->xrayFixture]===$original, 'Read-only profile navigation');

// Long template names must travel intact through Telegram's 64-byte callback limit.
$callback='/delTemplate sing '.base64_encode(str_repeat('длинное имя ',10));
$rows=$b->uiFitCallbacks([[['text'=>'Удалить шаблон','callback_data'=>$callback]]]);
$ref=$rows[0][0]['callback_data'];
adminCheck(strlen($ref)<=64,'Reference fits callback limit');
$b->input['callback']=$ref;
adminCheck(!$b->uiFlowPrepareAction() && $b->input['callback']===$callback,'Long callback resolves byte for byte');
$b->input['callback']=$ref; $b->input['from']=999;
adminCheck($b->uiFlowPrepareAction(),'Other user cannot resolve reference');
$b->input['from']=7; $b->input['callback']=$ref;
$token=substr($ref,9); $_SESSION['ui_callbacks'][$token]['time']=time()-1801;
adminCheck($b->uiFlowPrepareAction(),'Expired reference rejected');

// Translate field labels, never values/code, through actual production output.
$source="server: visible.example\n<pre>server: private.example\npassword: do-not-change</pre>";
(new ReflectionMethod(Bot::class,'update'))->invokeArgs($b,[1,8,$source,[[['text'=>'Назад','callback_data'=>'/menu']]]]);
$rendered=end($b->requests)[1]['text'];
adminCheck(str_contains($rendered,'Сервер: visible.example') && str_contains($rendered,'<pre>server: private.example'."\n".'password: do-not-change</pre>'),'Only external field labels translated');
$b->input['callback']='/warpMenu 0';
[$text]=$b->uiProfilesScreen($b->payload,[[['text'=>'Назад','callback_data'=>'/userXr 0']]]);
adminCheck(str_contains($text,$b->payload),'Special profile copy never rewrites payload');
$b->mode='classic'; $b->input['callback']='/profileui links 0'; $b->action();
adminCheck(end($b->requests)[1]['text']===$b->payload,'Classic profile preserved');
$b=new AdminUiTestBot();
foreach(['/generateSecret','/offWarp','/applyupdatebot','/switchBanIp','/cleanDeny 0','/cleanDeny 1','/clearLog 4','/delLog 4','/cleanLog',
 '/cleanLogs 192.0.2.1','/cleanLogs 192.0.2.1 1','/denyIp 192.0.2.1','/whiteIp 192.0.2.1','/allowIp 192.0.2.1 0 1',
 '/changeblocklist 0 0','/deletewarplist 0 0','/delTemplate sing '.base64_encode('fixture')] as $callback) {
    $token=adminPreview($b,$callback);
    $b->input['callback']='/cancelui '.$token;$b->action();
    adminCheck(empty($_SESSION['ui_review']) && $b->mutations===[],'Extended preview/cancel never changes services');
}
echo "PASS profile action reachability, payload fidelity, selected templates, isolated page state, callback references and classic fallback\n";
