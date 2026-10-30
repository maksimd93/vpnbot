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
check($bot->xrayFixture === $original, 'No mutation of existing users or transport');
check($bot->funnelLink(99) === '', 'Missing user');
$bot->pacFixture['eternal_funnel']['enabled'] = false;
check($bot->funnelLink(0) === '', 'Disabled fallback');
$bot->funnelMenu(0);
check(count($bot->rendered[1]) === 1, 'Stale menu does not offer a QR');
$bot->pacFixture['eternal_funnel']['enabled'] = true;
$bot->pacFixture['transport'] = 'Reality';
check($bot->funnelLink(0) === '', 'Do not offer XHTTP after transport changes');
echo "PASS: alternate link, identity, TLS, QR dispatch, menu, disabled and stale profiles\n";
