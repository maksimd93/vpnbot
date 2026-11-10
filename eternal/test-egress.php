<?php
// Runs the original import regression suite too; all accounts are fixtures.
require __DIR__ . '/test-direct443.php';
class EgressTestBot extends Direct443TestBot
{
    public $xray;
    public $rendered;
    public function __construct() {
        parent::__construct();
        $this->input['message_id'] = 2;
        $this->config['eternal_direct_443'] = true;
        $this->config['eternal_exit'] = ['enabled' => true,
            'owner_uuid' => $this->config['eternal_cdn']['uuid'],
            'uuid' => '00000000-0000-4000-8000-000000000002'];
        $this->xray = parent::getXray();
        $this->xray['inbounds'][0]['settings']['clients'][] = [
            'id' => $this->config['eternal_exit']['uuid'], 'email' => 'Eternal · WARP-выход', 'eternal_exit' => true];
        $this->xray['outbounds'] = [['tag' => 'direct', 'protocol' => 'freedom']];
        $this->xray['routing']['rules'] = [['type' => 'field', 'domain' => ['example.com'], 'outboundTag' => 'direct']];
    }
    public function getXray() { return $this->xray; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML') {
        $this->rendered = [$text, $button];
    }
}
function egCheck($ok, string $reason) { if (!$ok) throw new RuntimeException($reason); }
$b = new EgressTestBot();
$r = $b->eternalExitRouting($b->xray);
egCheck($r['routing']['rules'][0]['outboundTag'] === 'eternal-exit-warp'
    && $r['routing']['rules'][1] === $b->xray['routing']['rules'][0], 'Dedicated routing precedes ordinary rules');
egCheck($r['outbounds'][0] === $b->xray['outbounds'][0]
    && $r['outbounds'][1]['protocol'] === 'socks', 'Existing exit preserved');
egCheck($b->eternalExitRouting($r) === $r, 'Rebuild is idempotent');
egCheck($r['inbounds'] === $b->xray['inbounds'], 'No account mutation');
$legacy = $b->eternalAutoProfile();
foreach ([2, 1, 0] as $count) {
    $b->edges = array_slice(['203.0.113.22', '203.0.113.23'], 0, $count);
    $s = $b->eternalExitSources();
    egCheck(count($s['links']) === 3 + $count, 'Endpoint count');
    foreach ($s['links'] as $label => $link) {
        egCheck(parse_url($link, PHP_URL_USER) === $b->config['eternal_exit']['uuid'], 'Dedicated credential on every entry');
        parse_str(parse_url($link, PHP_URL_QUERY), $q);
        if ($q['type'] === 'xhttp') {
            $x = json_decode($q['extra'], true)['xmux'];
            egCheck($x['maxConnections'] === 0 && $x['maxConcurrency'] === '16-32'
                && $x['hKeepAlivePeriod'] === 15 && $x['hMaxReusableSecs'] === '180-300', 'Smooth XMUX');
            egCheck($q['security'] === 'tls' && $q['fp'] === 'chrome' && $q['alpn'] === 'h2', 'TLS settings');
            egCheck($q['sni'] === ($label === 'Прямой XHTTP' ? 'eternal.example' : 'test.example.ts.net'), 'Correct SNI');
        }
    }
    foreach (['lxbox', 'exclave', 'shadowrocket'] as $client) {
        $b->documents = [];
        $b->input['callback'] = '/eternalExit ' . $client;
        $b->action();
        egCheck(count($b->documents) === ($client === 'shadowrocket' ? 2 : 1), 'Documents dispatched');
        foreach ($b->documents as [$chat, $doc, $caption]) {
            egCheck($chat === 7 && mb_strlen($caption) <= 1024, 'Private destination and caption size');
            egCheck(!preg_match('/secretKey|privateKey|deviceId|DIRECT/', $doc->data), 'No WARP key or direct fallback in exports');
        }
        if ($client === 'lxbox') {
            $p = json_decode($b->documents[0][1]->data, true);
            $f = $p['storage']['sources'][0];
            egCheck($p['app'] === 'lxbox' && $p['kind'] === 'backup'
                && $f['id'] !== $legacy['storage']['sources'][0]['id'] && !isset($f['replace']), 'Separate native folder');
            $a = array_pop($f['nodes']);
            egCheck($a['kind'] === 'auto' && $a['tag'] === 'Eternal · WARP-выход'
                && count($a['group']['members']) === count($s['links']), 'Explicit auto node');
            foreach ($a['group']['members'] as $i => $member) {
                egCheck($member === ['folder_id' => $f['id'], 'tag' => $f['nodes'][$i]['tag']], 'Scoped members');
            }
        } elseif ($client === 'shadowrocket') {
            $uris = explode("\n", trim($b->documents[0][1]->data));
            $names = array_map(fn($u) => rawurldecode(parse_url($u, PHP_URL_FRAGMENT)), $uris);
            $policy = $b->documents[1][1]->data;
            egCheck(str_contains($policy, 'url-test,' . implode(',', $names) . ',interval=60')
                && str_ends_with($policy, "FINAL,Eternal · WARP-выход\n"), 'Policy matches emitted names');
        }
    }
}
$b->input['callback'] = '/eternalExitMenu';
$b->action();
egCheck(count($b->rendered[1]) === ($b->uiMode() === 'simple' ? count($b->uiClients()) + 1 : 4)
    && end($b->rendered[1])[0]['callback_data'] === '/menu hy', 'Menu and back navigation');
foreach (['disabled', 'missing-config', 'owner-disabled', 'client-disabled', 'owner-missing'] as $case) {
    $b = new EgressTestBot();
    if ($case === 'disabled') $b->config['eternal_exit']['enabled'] = false;
    if ($case === 'missing-config') unset($b->config['eternal_exit']);
    if ($case === 'owner-disabled') $b->xray['inbounds'][0]['settings']['clients'][0]['off'] = true;
    if ($case === 'client-disabled') $b->xray['inbounds'][0]['settings']['clients'][1]['off'] = true;
    if ($case === 'owner-missing') $b->config['eternal_exit']['owner_uuid'] = 'missing';
    $r = $b->eternalExitRouting($b->xray);
    egCheck($r['outbounds'][1]['protocol'] === 'blackhole' && $b->eternalExitSources() === [], 'Fail closed: ' . $case);
    $b->sendEternalExitFiles('lxbox');
    egCheck(!$b->documents && $b->reply[0] === 7, 'No stale credentials');
}
$b = new EgressTestBot();
$b->xray['inbounds'][0]['settings']['clients'][1]['email'] = 'Renamed';
egCheck($b->eternalExitRouting($b->xray)['routing']['rules'][0]['user'] === ['Renamed'], 'Renaming keeps dedicated routing');
parse_str(parse_url($b->funnelLink(0), PHP_URL_QUERY), $q);
egCheck(json_decode($q['extra'], true)['xmux']['maxConnections'] === 3, 'Legacy XMUX remains unchanged');
echo "PASS egress isolation, disabled-account fail-closed, smooth XMUX, three clients and scoped imports\n";
