<?php
// Offline presentation tests; original actions, Docker, SSH and Telegram are never executed.
require $argv[1] ?? __DIR__ . '/../app/bot.php';

class ServicesUiTestBot extends Bot
{
    public array $config = ['showdnstt' => true, 'warpoff' => true, 'adgbrowser' => false];
    public array $fixtures = [];
    public array $rendered = [];
    public function __construct() { $this->input = ['callback'=>'']; $this->language = 'ru'; }
    public function getPacConf() { return $this->config; }
    public function i18n(string $word): string { return $word; }
    public function menu($type = false, $arg = false, $return = false) {
        [$text, $buttons] = $this->fixtures[$type];
        $this->rendered[] = $this->uiServicesScreen($text, $buttons);
    }
    public function setPacConf(array $conf) { throw new RuntimeException('Unexpected configuration mutation'); }
    public function ssh($cmd, $service='wg', $wait=true, $log='/dev/null') { throw new RuntimeException('Unexpected SSH'); }
    public function request($method, $data, $json_header=0) { throw new RuntimeException('Unexpected Telegram request'); }
}
function serviceAssert($value, string $message): void { if (!$value) throw new RuntimeException($message); }
function serviceRows(array $callbacks): array {
    return array_map(fn($cb) => [['text' => match ($cb) {
        '/changeOcDns' => 'dns: 1.1.1.1', '/changeOcExpose' => 'expose-iroutes on', '/deloc 0' => 'delete alice',
        '/hidePort wg' => 'on 51820 WireGuard', '/v2ray' => 'v2ray: ON', '/menu adguard'=>'https://dns.example/dns-query', default=>$cb,
    }, 'callback_data'=>$cb]], $callbacks);
}
function serviceCallbacks(array $screen): array { return array_column(array_merge(...$screen[1]), 'callback_data'); }
function serviceLimits(array $screen): void {
    serviceAssert(mb_strlen(html_entity_decode(strip_tags($screen[0]), ENT_QUOTES, 'UTF-8')) <= 4096, 'Telegram text limit');
    foreach ($screen[1] as $row) foreach ($row as $button) {
        serviceAssert(!empty($button['text']), 'Button has a label');
        if (isset($button['callback_data'])) serviceAssert(strlen($button['callback_data']) <= 64, 'Callback byte limit');
    }
    serviceAssert(in_array('/menu', serviceCallbacks($screen), true), 'Every screen has home');
}
$b = new ServicesUiTestBot();
$secret = '<code>https://vpn.example/?fixture-secret</code>';
$ocActions = ['/changeOcDomain','/changeCamouflage','/changeOcPass','/changeOcDns','/subnet 0_0_1','/changeOcExpose','/addOcUser','/deloc 0'];
$b->fixtures['oc'] = ["Menu -> OpenConnect\n$secret", serviceRows([...$ocActions, '/menu'])];
$dnsActions = ['/adguardChBr','/adguardpsswd','/setAdguardKey','/adgFillAllowedClients 0','/adgFillAllowedClients 1','/checkdns','/adguardreset','/addupstream','/menu adguard','/delupstream 0'];
$dnsButtons = serviceRows([...$dnsActions, '/menu']);
array_unshift($dnsButtons, [['text'=>'web panel', 'web_app'=>['url'=>'https://vpn.example/adguard']]]);
$b->fixtures['adguard'] = [$secret, $dnsButtons];

foreach (['oc' => ['oc', ['connection','users','settings'], $ocActions],
          'dns' => ['adguard', ['connection','access','upstreams','settings'], $dnsActions]] as $service => [$menu, $pages, $actions]) {
    $b->input['callback'] = "/serviceui $service main";
    serviceAssert($b->uiServicesAction(), 'Grouped service callback handled');
    $home = end($b->rendered);
    serviceLimits($home);
    serviceAssert(!str_contains($home[0], 'fixture-secret'), 'Overview does not dump credentials');
    $seen = [];
    foreach ($pages as $page) {
        serviceAssert(in_array("/serviceui $service $page", serviceCallbacks($home), true), 'Group accessible from overview');
        $b->input['callback'] = "/serviceui $service $page";
        serviceAssert($b->uiServicesAction(), 'Subpage callback handled');
        $screen = end($b->rendered);
        serviceLimits($screen);
        serviceAssert(in_array("/serviceui $service main", serviceCallbacks($screen), true), 'Subpage goes back to correct service');
        if ($page === 'connection') serviceAssert(str_contains($screen[0], $secret), 'Connection payload preserved exactly');
        else serviceAssert(!str_contains($screen[0], 'fixture-secret'), 'Settings separate from credentials');
        $seen = array_merge($seen, serviceCallbacks($screen));
    }
    serviceAssert(!array_diff($actions, $seen), 'All original service actions remain reachable');
    $b->menu($menu);
    serviceAssert(end($b->rendered) === $home, 'Page selection does not leak to next request');
}

$single = [
    'telegram' => ['/generateSecret','/setSecret','/changeTGDomain','/qrMtproto'],
    'dnstt' => ['/showdnstt','/dnsttDomain','/dnsttPassword','/dnsttDownload'],
    'warp' => ['/offWarp','/warpPlus'], 'mirror'=>['/getMirror'], 'branches'=>['/changeBranch 0'],
    'update'=>['/branches','/applyupdatebot'], 'ports'=>['/hidePort wg','/changePort hy','/restart'],
    'language'=>['/lang en'], 'reset'=>['/reset'], 'hysteria'=>['/changeHysteriaPass','/eternalAutoFile','/eternalExclave','/eternalShadowrocket','/eternalExitMenu','/xray'],
    'naive'=>['/changeNaiveSubdomain','/changeNaiveUser','/changeNaivePass'], 'ss'=>['/sspswd','/v2ray','/qrSS'],
];
$b->input['callback'] = '';
foreach ($single as $kind => $callbacks) {
    $screen = $b->uiServicesScreen($secret, serviceRows([...$callbacks, '/menu']));
    serviceAssert(is_array($screen), 'Service detected: ' . $kind);
    serviceLimits($screen);
    serviceAssert(!array_diff($callbacks, serviceCallbacks($screen)), 'Callbacks preserved: ' . $kind);
    if (!in_array($kind, ['language','branches','reset'], true)) serviceAssert(str_contains($screen[0], $secret), 'Payload preserved: ' . $kind);
    if ($kind === 'warp') serviceAssert(str_contains($screen[0], 'другом контейнере'), 'Old WARP is distinguished from server egress');
}
serviceAssert($b->uiServicesScreen('unrelated', serviceRows(['/xray'])) === null, 'Unrelated flow left for another adapter');
$b->input['callback'] = '/serviceui oc invalid';
serviceAssert(!$b->uiServicesAction(), 'Unknown action is not forwarded');
foreach (['/addOcUser','/setSecret','/changeTGDomain','/dnsttDomain','/dnsttPassword','/warpPlus','/backup','/import','/domain','/selfssl',
          '/adguardpsswd','/setAdguardKey','/checkdns','/addupstream','/addadmin','/addSubdomain','/enterPage','/addOverrideHtml'] as $callback) {
    $b->input['callback'] = $callback;
    $prompt = $b->uiServicesPrompt('@ enter value');
    serviceAssert(is_string($prompt) && $prompt !== '' && mb_strlen($prompt) < 4096, 'Prompt explained: ' . $callback);
    serviceAssert($b->uiServicesPrompt('An error after submission') === null, 'Error responses are not replaced with prompts');
}
$b->input['callback'] = '/selfssl';
serviceAssert(str_contains($b->uiServicesPrompt('@name send file'), 'PEM'), 'Custom certificate upload explained accurately');
echo "PASS service hierarchy, full action reachability, credential preservation, navigation and input explanations\n";
