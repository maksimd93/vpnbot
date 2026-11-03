<?php
// Offline fixtures only: no Telegram API, real keys, or production files.
require $argv[1] ?? __DIR__ . '/../app/bot.php';
class CdnTestBot extends Bot
{
    public $pacFixture;
    public $xrayFixture;
    public $rendered;
    public function __construct()
    {
        $uuid = '00000000-0000-4000-8000-000000000001';
        $this->input = ['chat' => 1, 'message_id' => 2, 'message' => '', 'callback' => ''];
        $profile = ['outbounds' => [
            ['type' => 'masque', 'tag' => 'first-hop', 'server' => '162.159.198.2', 'server_port' => 443,
                'profile' => 'cloudflare', 'vhttp' => 'h3', 'mtu' => 1280, 'ip' => '172.16.0.2/32',
                'private_key' => 'FAKE-P256-PRIVATE', 'public_key' => 'FAKE-P256-PEER',
                'tls' => ['enabled' => true, 'server_name' => 'www.cloudflare.com']],
            ['type' => 'vless', 'tag' => 'Eternal · Turbo', 'uuid' => $uuid,
                'server' => '188.114.97.1', 'server_port' => 443, 'detour' => 'first-hop',
                'tls' => ['enabled' => true, 'server_name' => 'vpn.example.com', 'alpn' => ['h2'],
                    'utls' => ['enabled' => true, 'fingerprint' => 'chrome']],
                'transport' => ['type' => 'xhttp', 'mode' => 'packet-up', 'host' => 'vpn.example.com', 'path' => '/vpn/path']],
        ]];
        $this->pacFixture = [
            'transport' => 'xhttp', 'hwid_device_count' => 1, 'hwid_limit_enabled' => false,
            'defaultsingtemplate' => '', 'defaultv2raytemplate' => '', 'defaultclashtemplate' => '',
            'eternal_cdn' => ['enabled' => true, 'uuid' => $uuid, 'host' => 'vpn.example.com',
                'server' => '188.114.97.1', 'port' => 443, 'live_path' => '/vpn/path', 'profile' => $profile],
            'eternal_funnel' => ['enabled' => true, 'host' => 'vpn.example.ts.net', 'port' => 443],
            'eternal_warp' => ['enabled' => true, 'uuid' => $uuid, 'profile' => [
                'outbounds' => [['type' => 'vless', 'uuid' => $uuid, 'transport' => ['type' => 'xhttp', 'path' => '/vpn/path']]],
                'endpoints' => [['type' => 'wireguard', 'private_key' => 'OLD-WG-DO-NOT-EXPORT']],
            ]],
        ];
        $client = ['id' => $uuid, 'email' => "A & B's phone", 'off' => '', 'time' => 0,
            'hwid_limit' => 0, 'singtemplate' => '', 'v2raytemplate' => '', 'clashtemplate' => ''];
        $this->xrayFixture = ['inbounds' => [[
            'settings' => ['clients' => [$client, array_merge($client, ['id' => '00000000-0000-4000-8000-000000000002'])]],
            'streamSettings' => ['network' => 'xhttp', 'xhttpSettings' => ['path' => '/vpn/path']],
        ]]];
    }
    public function getPacConf() { return $this->pacFixture; }
    public function getXray() { return $this->xrayFixture; }
    public function getDomain($cdn = false) { return 'original.example'; }
    public function getHashBot($notset = false) { return 'test'; }
    public function nginxGetTypeCert() { return 'letsencrypt'; }
    public function getHwidDevicesByUser($uid) { return []; }
    public function getXrayStats() { return ['users' => [['global' => ['download' => 0, 'upload' => 0], 'session' => ['download' => 0, 'upload' => 0]]]]; }
    public function linkXray($i, $s = false) { return 'vless://TEST-ORIGINAL'; }
    public function i18n(string $menu): string { return $menu; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML') { $this->rendered = [$text, $button]; }
}
function cdnCheck($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$bot = new CdnTestBot();
$originalXray = $bot->xrayFixture;
$originalPac = $bot->pacFixture;
$expected = $originalPac['eternal_cdn']['profile'];
cdnCheck(json_decode($bot->cdnProfile(0), true) === $expected, 'Complete MASQUE and VLESS chain survives serialization');
$bot->input['callback'] = '/cdnMenu 0';
$bot->action();
cdnCheck(preg_match('~<pre><code>(.*?)</code></pre>~s', $bot->rendered[0], $m) === 1, 'One copyable JSON block');
cdnCheck(json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) === $expected, 'Copy preserves both keys, TLS settings and detour');
cdnCheck(strpos($bot->rendered[0], 'OLD-WG-DO-NOT-EXPORT') === false && strpos($bot->rendered[0], 'ss://') === false, 'Do not export other account keys or abandoned SS URI');
cdnCheck(strpos($bot->rendered[0], 'Shadowrocket') === false, 'Only advertise the implemented LxBox import');
cdnCheck(mb_strlen(html_entity_decode(strip_tags($bot->rendered[0]), ENT_QUOTES, 'UTF-8')) < 4096, 'Menu fits Telegram');
foreach (['userXr', 'funnelMenu', 'warpMenu'] as $menu) {
    $bot->pacFixture['eternal_cdn']['enabled'] = false;
    $bot->$menu(0); $oldButtons = $bot->rendered[1];
    $bot->pacFixture['eternal_cdn']['enabled'] = true;
    $bot->$menu(0);
    $withoutCdn = array_values(array_filter($bot->rendered[1], fn($row) => ($row[0]['callback_data'] ?? '') !== '/cdnMenu 0'));
    cdnCheck(count($bot->rendered[1]) === count($oldButtons) + 1 && $withoutCdn === $oldButtons, 'CDN button preserves existing buttons in ' . $menu);
}
cdnCheck($bot->cdnProfile(1) === '' && $bot->cdnProfile(99) === '', 'No profile for another or missing user');
$bot->xrayFixture['inbounds'][0]['settings']['clients'][0]['off'] = 'disabled';
cdnCheck($bot->cdnProfile(0) === '', 'No profile after disabling owner');
$bot->xrayFixture = $originalXray;
$bot->xrayFixture['inbounds'][0]['settings']['clients'][0]['id'] = 'rotated-owner';
cdnCheck($bot->cdnProfile(0) === '', 'No profile after owner UUID rotation');
$bot->xrayFixture = $originalXray;
$bot->xrayFixture['inbounds'][0]['streamSettings']['network'] = 'grpc';
cdnCheck($bot->cdnProfile(0) === '', 'Live inbound must remain XHTTP');
$bot->xrayFixture = $originalXray;
$bot->pacFixture['transport'] = 'Reality';
cdnCheck($bot->cdnProfile(0) === '', 'PAC transport must remain XHTTP');
$bot->pacFixture = $originalPac;
$bot->xrayFixture['inbounds'][0]['streamSettings']['xhttpSettings']['path'] = '/new-live-path';
cdnCheck($bot->cdnProfile(0) === '', 'Hide stale path snapshot');
$bot->xrayFixture = $originalXray;
$mutations = [
    function (&$c) { $c['uuid'] = 'wrong-owner'; },
    function (&$c) { $c['host'] = 'invalid host.example'; },
    function (&$c) { $c['host'] = '127.0.0.1'; },
    function (&$c) { $c['server'] = 'unconfigured.example'; },
    function (&$c) { $c['port'] = 444; },
    function (&$c) { $c['profile']['outbounds'][1]['uuid'] = 'wrong-user'; },
    function (&$c) { $c['profile']['outbounds'][1]['detour'] = 'missing-hop'; },
    function (&$c) { $c['profile']['outbounds'][1]['server'] = '146.103.110.71'; },
    function (&$c) { $c['profile']['outbounds'][1]['tls']['server_name'] = '188.114.97.1'; },
    function (&$c) { $c['profile']['outbounds'][1]['tls']['insecure'] = true; },
    function (&$c) { $c['profile']['outbounds'][1]['transport']['host'] = 'wrong.example'; },
    function (&$c) { $c['profile']['outbounds'][1]['transport']['path'] = '/stale-path'; },
    function (&$c) { $c['profile']['outbounds'][0]['tls']['insecure'] = true; },
    function (&$c) { $c['profile']['outbounds'][0]['vhttp'] = 'h2'; },
    function (&$c) { $c['profile']['outbounds'][0]['type'] = 'wireguard'; },
    function (&$c) { $c['profile']['outbounds'][0]['bind_interface'] = 'Windows Wi-Fi'; },
    function (&$c) { $c['profile']['outbounds'][0]['private_key'] = str_repeat('x', 4000); },
    function (&$c) { $c['profile']['endpoints'] = [['type' => 'wireguard', 'private_key' => 'EXTRA-WG']]; },
    function (&$c) { $c['profile']['outbounds'][] = ['type' => 'direct']; },
];
foreach ($mutations as $number => $mutate) {
    $bot->pacFixture = $originalPac;
    $mutate($bot->pacFixture['eternal_cdn']);
    cdnCheck($bot->cdnProfile(0) === '', 'Reject unsafe/stale profile mutation ' . $number);
}
$bot->pacFixture = $originalPac;
$bot->pacFixture['eternal_cdn']['enabled'] = false;
$bot->cdnMenu(0);
cdnCheck(strpos($bot->rendered[0], 'FAKE-P256-PRIVATE') === false && strpos($bot->rendered[0], '<pre>') === false, 'Stale callback does not expose disabled key');
$bot->pacFixture = $originalPac;
cdnCheck($bot->xrayFixture === $originalXray && $bot->pacFixture === $originalPac, 'No mutation of server/owner fixtures');
$uuid = $originalPac['eternal_cdn']['uuid'];
$masqueQuery = http_build_query(['publicKey' => 'FAKE-SR-PEER+/=', 'privateKey' => 'FAKE-SR-PRIVATE+/=',
    'ip' => '172.16.0.2', 'dns' => '1.1.1.1,8.8.8.8', 'udp' => '1'], '', '&', PHP_QUERY_RFC3986);
$vlessQuery = http_build_query(['encryption' => 'none', 'security' => 'tls', 'sni' => 'vpn.example.com',
    'host' => 'vpn.example.com', 'type' => 'xhttp', 'path' => '/vpn/path', 'mode' => 'packet-up',
    'alpn' => 'h2', 'fp' => 'chrome'], '', '&', PHP_QUERY_RFC3986);
$sr = ['uuid' => $uuid, 'masque_uri' => "masque://162.159.198.2:443?$masqueQuery#MASQUE-SR",
    'vless_uri' => "vless://$uuid@188.114.97.1:443?$vlessQuery#Eternal-Turbo-SR"];
$bot->pacFixture['eternal_cdn']['shadowrocket'] = $sr;
cdnCheck($bot->cdnShadowrocketProfile(0) === $sr, 'Separate Shadowrocket account and same VLESS owner');
$bot->cdnMenu(0);
cdnCheck($bot->rendered[1][0][0]['callback_data'] === '/cdnShadowrocketMenu 0_1', 'Shadowrocket is discoverable without changing the Android import');
cdnCheck(strpos($bot->rendered[0], 'FAKE-SR-PRIVATE') === false, 'Android JSON does not include the separate iPad key');
foreach ([1, 2] as $step) {
    $bot->input['callback'] = "/cdnShadowrocketMenu 0_$step";
    $bot->action();
    cdnCheck(preg_match('~<pre><code>(.*?)</code></pre>~s', $bot->rendered[0], $m) === 1, 'Copyable Shadowrocket import');
    cdnCheck(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') === $sr[$step === 1 ? 'masque_uri' : 'vless_uri'], 'Shadowrocket URI query survives HTML copying');
    cdnCheck(mb_strlen(html_entity_decode(strip_tags($bot->rendered[0]), ENT_QUOTES, 'UTF-8')) < 4096, 'Shadowrocket screen fits Telegram');
    cdnCheck($bot->rendered[1][0][0]['callback_data'] === '/cdnShadowrocketMenu 0_' . (3 - $step), 'Both Shadowrocket setup steps are reachable');
    if ($step === 1) cdnCheck(strpos($bot->rendered[0], 'SNI www.cloudflare.com') !== false && strpos($bot->rendered[0], 'HTTP/3') !== false, 'Require manual MASQUE SNI and H3 settings');
}
foreach ([
    ['uuid', 'another-owner'],
    ['masque_uri', str_repeat('m', 2001)],
    ['masque_uri', str_replace('masque://', 'https://', $sr['masque_uri'])],
    ['masque_uri', str_replace('FAKE-SR-PRIVATE%2B%2F%3D', 'FAKE-P256-PRIVATE', $sr['masque_uri'])],
    ['vless_uri', str_replace('security=tls', 'security=none', $sr['vless_uri'])],
    ['vless_uri', str_replace('sni=vpn.example.com', 'sni=188.114.97.1', $sr['vless_uri'])],
    ['vless_uri', str_replace('path=%2Fvpn%2Fpath', 'path=%2Fstale', $sr['vless_uri'])],
] as [$field, $value]) {
    $bot->pacFixture['eternal_cdn']['shadowrocket'] = array_merge($sr, [$field => $value]);
    cdnCheck($bot->cdnShadowrocketProfile(0) === [], 'Reject unsafe/stale Shadowrocket child');
}
$bot->pacFixture['eternal_cdn']['shadowrocket'] = $sr;
$bot->pacFixture['eternal_cdn']['enabled'] = false;
$bot->cdnShadowrocketMenu(0, 1);
cdnCheck(strpos($bot->rendered[0], 'FAKE-SR-PRIVATE') === false && strpos($bot->rendered[0], '<pre>') === false, 'Disabled parent does not disclose a Shadowrocket key');
$bot->pacFixture = $originalPac;
cdnCheck($bot->cdnProfile(0, true) === '', 'TCP profile is optional');
$bot->cdnMenu(0);
cdnCheck(count($bot->rendered[1]) === 1, 'No TCP button before a valid optional profile exists');
$tcp = $expected;
$tcp['outbounds'][0]['vhttp'] = 'h2';
$tcp['outbounds'][0]['private_key'] = 'FAKE-LTE-SEPARATE-P256';
$tcp['outbounds'][1]['tag'] = 'Eternal · LTE';
$bot->pacFixture['eternal_cdn']['tcp_profile'] = $tcp;
cdnCheck(json_decode($bot->cdnProfile(0, true), true) === $tcp, 'Separate H2/TCP profile survives serialization');
cdnCheck(json_decode($bot->cdnProfile(0), true) === $expected, 'H3 profile remains unchanged');
foreach ([false, true] as $tcpSelected) {
    $bot->input['callback'] = '/cdnMenu 0' . ($tcpSelected ? '_1' : '');
    $bot->action();
    cdnCheck(preg_match('~<pre><code>(.*?)</code></pre>~s', $bot->rendered[0], $m) === 1, 'Both Android transport menus expose one JSON block');
    cdnCheck(json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) === ($tcpSelected ? $tcp : $expected), 'UTF-8 label and account survive transport menu copying');
    cdnCheck($bot->rendered[1][0][0]['callback_data'] === '/cdnMenu 0' . ($tcpSelected ? '' : '_1'), 'Switch between H3 and optional LTE/TCP');
}
$bot->pacFixture['eternal_cdn']['tcp_profile']['outbounds'][0]['private_key'] = $expected['outbounds'][0]['private_key'];
cdnCheck($bot->cdnProfile(0, true) === '', 'Do not reuse the active H3 phone key for the separate TCP tunnel');
$bot->pacFixture['eternal_cdn']['tcp_profile'] = $tcp;
$bot->pacFixture['eternal_cdn']['tcp_profile']['outbounds'][0]['tls']['utls'] = ['enabled' => true];
cdnCheck($bot->cdnProfile(0, true) === '', 'MASQUE H2 requires standard TLS, not uTLS');
$bot->pacFixture['eternal_cdn']['tcp_profile'] = $tcp;
$bot->pacFixture['eternal_cdn']['tcp_profile']['outbounds'][0]['vhttp'] = 'h3';
cdnCheck($bot->cdnProfile(0, true) === '', 'TCP entry cannot silently use H3');
$bot->pacFixture['eternal_cdn']['tcp_profile'] = $tcp;
cdnCheck($bot->cdnProfile(1, true) === '' && $bot->cdnProfile(99, true) === '', 'TCP does not disclose a key to another/missing user');
$bot->xrayFixture['inbounds'][0]['settings']['clients'][0]['off'] = 'disabled';
cdnCheck($bot->cdnProfile(0, true) === '', 'TCP inherits owner disable guard');
$bot->xrayFixture = $originalXray;
$bot->pacFixture['eternal_cdn']['shadowrocket'] = $sr;
cdnCheck($bot->cdnShadowrocketProfile(0) === $sr, 'Adding LTE/TCP does not alter the existing Shadowrocket H3 setup');
$bot->pacFixture['eternal_cdn']['enabled'] = false;
$bot->cdnMenu(0, true);
cdnCheck(strpos($bot->rendered[0], 'FAKE-LTE-SEPARATE-P256') === false && strpos($bot->rendered[0], '<pre>') === false, 'Disabled CDN does not expose the TCP account');
echo "PASS: Turbo H3, optional separate LTE/TCP, Shadowrocket imports, TLS, ownership, copying and stale-config guards; no Telegram calls\n";
