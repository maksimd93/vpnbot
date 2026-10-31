<?php
// Offline: no Telegram requests, SSH, or production configuration writes.
require $argv[1] ?? __DIR__ . '/../app/bot.php';

class FunnelTestBot extends Bot
{
    public $pacFixture;
    public $xrayFixture;
    public $rendered;
    public $qrSelection;

    public function __construct()
    {
        $this->input = ['chat' => 1, 'message_id' => 2, 'message' => '', 'callback' => ''];
        $this->pacFixture = ['transport' => 'xhttp', 'eternal_funnel' => [
            'enabled' => true, 'host' => 'vpn.example.ts.net', 'port' => 10000,
        ]];
        $this->xrayFixture = ['inbounds' => [[
            'settings' => ['clients' => [[
                'id' => '00000000-0000-4000-8000-000000000001', 'email' => "A & B's phone",
            ]]],
            'streamSettings' => ['network' => 'xhttp', 'xhttpSettings' => ['path' => '/vpn/path']],
        ]]];
    }
    public function getPacConf() { return $this->pacFixture; }
    public function getXray() { return $this->xrayFixture; }
    public function getDomain($cdn = false) { return 'original.example'; }
    public function getHashBot($notset = false) { return 'test'; }
    public function nginxGetTypeCert() { return 'letsencrypt'; }
    public function i18n(string $menu): string { return $menu; }
    public function qrXray($i, $s = false) { $this->qrSelection = [$i, $s]; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML')
    {
        $this->rendered = [$text, $button];
    }
}
function check($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
}
$bot = new FunnelTestBot();
$original = $bot->xrayFixture;
$link = $bot->funnelLink(0);
$parts = parse_url($link);
parse_str($parts['query'], $query);
check($parts['host'] === 'vpn.example.ts.net' && $parts['port'] === 10000, 'Alternate endpoint');
check($parts['user'] === $original['inbounds'][0]['settings']['clients'][0]['id'], 'Existing user identity');
check($query['security'] === 'tls' && $query['sni'] === $parts['host'], 'TLS verification hostname');
check($query['path'] === '/vpn/path' && $query['mode'] === 'packet-up', 'XHTTP transport');
check(strpos($link, "'") === false, 'QR shell argument contains no literal quote');
check($bot->linkXray(0, 4) === $link, 'QR selector chooses the fallback profile');
$bot->input['callback'] = '/funnelMenu 0';
$bot->action();
check(strpos($bot->rendered[0], '&amp;') !== false, 'Telegram HTML escapes URL separators');
check($bot->rendered[1][0][0]['callback_data'] === '/qrXray 0_4', 'QR callback dispatch');
$bot->input['callback'] = $bot->rendered[1][0][0]['callback_data'];
$bot->action();
check($bot->qrSelection === ['0', '4'], 'QR button reaches the expected handler');
check($bot->funnelLink(0, true) === '', 'No duplicate legacy link before 443 is enabled');
$bot->pacFixture['eternal_funnel']['port'] = 443;
$mobile = parse_url($bot->funnelLink(0));
parse_str($mobile['query'], $mobileQuery);
$extra = json_decode($mobileQuery['extra'], true);
check($mobile['port'] === 443 && $mobile['user'] === $parts['user'], '443 keeps the same identity');
check($mobileQuery['alpn'] === 'h2' && $mobileQuery['host'] === $mobile['host'], 'Explicit HTTP/2 and Host');
check($mobileQuery['path'] === $query['path'] && $mobileQuery['mode'] === 'packet-up', '443 uses the existing XHTTP path');
check($extra['xmux']['hKeepAlivePeriod'] === 15 && $extra['xmux']['hMaxReusableSecs'] === '180-300', 'Idle health check and connection rotation');
check($extra['xmux']['maxConnections'] === 3 && $extra['xmux']['hMaxRequestTimes'] === '600-900', 'Preserve pool defaults with explicit XMUX');
$old = parse_url($bot->funnelLink(0, true));
parse_str($old['query'], $oldQuery);
check($old['port'] === 10000 && $old['user'] === $parts['user'] && $oldQuery === $query, 'Legacy transport is unchanged');
check($bot->linkXray(0, 5) === $bot->funnelLink(0, true), 'Legacy QR selector');
$bot->funnelMenu(0);
check($bot->rendered[1][1][0]['callback_data'] === '/qrXray 0_5', 'Legacy QR button');
check(strlen($bot->rendered[0]) < 4096, 'Menu remains within Telegram message limit');
$bot->input['callback'] = '/qrXray 0_5';
$bot->action();
check($bot->qrSelection === ['0', '5'], 'Legacy QR dispatch');
$bot->pacFixture['eternal_funnel']['port'] = 444;
check($bot->funnelLink(0) === '' && $bot->funnelLink(0, true) === '', 'Reject an unconfigured port');
$bot->pacFixture['eternal_funnel']['port'] = 443;
check($bot->xrayFixture === $original, 'No mutation of existing users or transport');
check($bot->funnelLink(99) === '', 'Missing user');
$bot->pacFixture['eternal_funnel']['enabled'] = false;
check($bot->funnelLink(0) === '', 'Disabled fallback');
$bot->funnelMenu(0);
check(count($bot->rendered[1]) === 1, 'Stale menu does not offer a QR');
$bot->pacFixture['eternal_funnel']['enabled'] = true;
$bot->pacFixture['transport'] = 'Reality';
check($bot->funnelLink(0) === '', 'Do not offer XHTTP after transport changes');
$bot->pacFixture['transport'] = 'xhttp';
$warpFixture = [
    'outbounds' => [[
        'type' => 'vless', 'tag' => 'Eternal · WARP', 'detour' => 'warp',
        'uuid' => $original['inbounds'][0]['settings']['clients'][0]['id'],
        'transport' => ['type' => 'xhttp', 'path' => '/vpn/path'],
    ]],
    'endpoints' => [['type' => 'wireguard', 'tag' => 'warp', 'private_key' => 'TEST-ONLY']],
];
$bot->pacFixture['eternal_warp'] = [
    'enabled' => true, 'uuid' => $warpFixture['outbounds'][0]['uuid'], 'profile' => $warpFixture,
];
check(json_decode($bot->warpProfile(0), true) === $warpFixture, 'Complete detour profile survives serialization');
$bot->input['callback'] = '/warpMenu 0';
$bot->action();
check(strpos($bot->rendered[0], 'Eternal · WARP') !== false, 'WARP callback renders import instructions');
check(strpos($bot->rendered[0], '&quot;detour&quot;') !== false, 'Profile is escaped as Telegram HTML');
check(mb_strlen(strip_tags(html_entity_decode($bot->rendered[0]))) < 4096, 'WARP menu fits Telegram');
$bot->funnelMenu(0);
check($bot->rendered[1][2][0]['callback_data'] === '/warpMenu 0', 'Fallback menu offers fast profile');
check($bot->warpProfile(99) === '', 'No profile for a missing user');
$bot->xrayFixture['inbounds'][0]['settings']['clients'][0]['id'] = '00000000-0000-4000-8000-000000000002';
check($bot->warpProfile(0) === '', 'No WARP key disclosure to another or disabled user');
$bot->xrayFixture = $original;
$bot->pacFixture['eternal_warp']['profile']['outbounds'][0]['uuid'] = 'mismatched-profile';
check($bot->warpProfile(0) === '', 'Reject a profile prepared for another user');
$bot->pacFixture['eternal_warp']['profile'] = $warpFixture;
$bot->xrayFixture['inbounds'][0]['streamSettings']['xhttpSettings']['path'] = '/changed';
check($bot->warpProfile(0) === '', 'Hide a stale transport profile');
$bot->xrayFixture = $original;
$bot->pacFixture['eternal_warp']['enabled'] = false;
check($bot->warpProfile(0) === '', 'Disabled WARP profile');
$bot->warpMenu(0);
check(strpos($bot->rendered[0], 'TEST-ONLY') === false, 'Stale callback does not reveal a disabled key');
$bot->pacFixture['eternal_warp']['enabled'] = true;
$uuid = $warpFixture['outbounds'][0]['uuid'];
$sr = ['uuid' => $uuid,
    'wireguard_conf' => "[Interface]\nPrivateKey = TEST-SR\nI1 = <b 0x0102>\n",
    'vless_uri' => "vless://$uuid@example.com:443?security=tls&type=xhttp#Eternal-SR",
];
$bot->pacFixture['eternal_warp']['shadowrocket'] = $sr;
$bot->warpMenu(0);
check($bot->rendered[1][0][0]['callback_data'] === '/shadowrocketMenu 0_1', 'Shadowrocket entry button');
foreach ([1, 2] as $step) {
    $bot->input['callback'] = "/shadowrocketMenu 0_$step";
    $bot->action();
    check(preg_match('~<pre><code>(.*?)</code></pre>~s', $bot->rendered[0], $m) === 1, 'Copyable import block');
    check(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') === $sr[$step === 1 ? 'wireguard_conf' : 'vless_uri'], 'CPS/URI survive Telegram HTML encoding');
    check($bot->rendered[1][0][0]['callback_data'] === '/shadowrocketMenu 0_' . (3 - $step), 'Navigate both import steps');
}
$bot->pacFixture['eternal_warp']['shadowrocket']['uuid'] = 'another-user';
check($bot->shadowrocketProfile(0) === [], 'Shadowrocket profile ownership');
$bot->pacFixture['eternal_warp']['shadowrocket'] = $sr;
$bot->pacFixture['eternal_warp']['shadowrocket']['vless_uri'] = 'vless://another-user@example.com:443';
check($bot->shadowrocketProfile(0) === [], 'VLESS URI ownership');
$bot->pacFixture['eternal_warp']['shadowrocket'] = $sr;
$bot->pacFixture['eternal_warp']['enabled'] = false;
$bot->shadowrocketMenu(0);
check(strpos($bot->rendered[0], 'TEST-SR') === false, 'Disabled Shadowrocket credentials stay private');
$bot->pacFixture['eternal_warp']['enabled'] = true;
$alternative = $warpFixture;
$alternative['outbounds'][0]['tag'] = 'Eternal · WARP 2';
$alternative['endpoints'][0]['private_key'] = 'TEST-ALTERNATIVE';
$alternative['endpoints'][0]['i1'] = '<b 0x' . str_repeat('ab', 1250) . '>';
$bot->pacFixture['eternal_warp']['android_alternative'] = [
    'enabled' => true, 'uuid' => $uuid, 'profile' => $alternative,
];
$bot->input['callback'] = '/warpAlternativeMenu 0';
$bot->action();
check(preg_match('~<pre><code>(.*?)</code></pre>~s', $bot->rendered[0], $m) === 1, 'Alternative callback exports an import');
check(json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) === $alternative, 'Long CPS and new key survive copying');
check(mb_strlen(html_entity_decode(strip_tags($bot->rendered[0]), ENT_QUOTES, 'UTF-8')) < 4096, 'Alternative fits Telegram');
$bot->warpMenu(0);
check(in_array('/warpAlternativeMenu 0', array_column(array_merge(...$bot->rendered[1]), 'callback_data'), true), 'Alternative is discoverable');
check($bot->warpAlternativeProfile(99) === '', 'No alternative for a missing user');
$bot->xrayFixture['inbounds'][0]['settings']['clients'][0]['id'] = 'disabled-user';
check($bot->warpAlternativeProfile(0) === '', 'No alternative disclosure after user disable');
$bot->xrayFixture = $original;
$bot->pacFixture['eternal_warp']['android_alternative']['profile']['outbounds'][0]['uuid'] = 'wrong-user';
check($bot->warpAlternativeProfile(0) === '', 'Reject alternative UUID mismatch');
$bot->pacFixture['eternal_warp']['android_alternative']['profile'] = $alternative;
$bot->pacFixture['eternal_warp']['android_alternative']['profile']['outbounds'][0]['transport']['path'] = '/stale';
check($bot->warpAlternativeProfile(0) === '', 'Reject stale alternative transport');
$bot->pacFixture['eternal_warp']['android_alternative']['profile'] = $alternative;
$bot->pacFixture['eternal_warp']['android_alternative']['enabled'] = false;
$bot->warpAlternativeMenu(0);
check(strpos($bot->rendered[0], 'TEST-ALTERNATIVE') === false, 'Disabled alternative stays private');

echo "PASS: fallback links, TLS, QR, WARP/Shadowrocket imports, CPS escaping and per-user isolation\n";
