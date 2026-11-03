<?php
// Offline endpoint generation. All credentials are fixtures.
require $argv[1] ?? __DIR__ . '/../app/bot.php';
class Direct443TestBot extends Bot
{
    public $config;
    public $documents = [];
    public $reply;
    public $edges = ['203.0.113.22', '203.0.113.23'];
    public function __construct() {
        $this->ip = '192.0.2.50';
        $this->input = ['chat' => 1, 'from' => 7, 'callback' => '', 'message' => ''];
        $this->config = ['domain' => 'eternal.example', 'hysteria_pass' => 'a@b:c &/%', 'transport' => 'xhttp',
            'eternal_cdn' => ['uuid' => '00000000-0000-4000-8000-000000000001'],
            'eternal_funnel' => ['enabled' => true, 'host' => 'test.example.ts.net', 'port' => 443],
            'grpc_reality' => ['enabled' => true, 'domain' => 'sni.example', 'service' => 'example',
                'publicKey' => 'TEST', 'shortId' => '12']];
    }
    public function getPacConf() { return $this->config; }
    public function getXray() { return ['inbounds' => [['streamSettings' => ['network' => 'xhttp', 'xhttpSettings' => ['path' => '/test']],
        'settings' => ['clients' => [
        ['id' => '00000000-0000-4000-8000-000000000001', 'email' => 'test']]]]]]; }
    public function i18n(string $menu): string { return $menu; }
    public function funnelAddresses(string $host): array { return $this->edges; }
    public function sendFile($chat, $file, $caption = false, $to = false) {
        $this->documents[] = [$chat, $file, $caption];
    }
    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false) {
        $this->reply = [$chat, $text];
    }
}
function checkDirect443($result) { if (!$result) throw new RuntimeException('Direct 443 profile mismatch'); }
$b = new Direct443TestBot();
$legacy = parse_url($b->grpcRealityLink(0));
checkDirect443($b->directHysteriaLink() === '' && $legacy['host'] === 'eternal.example');
$b->config['eternal_direct_443'] = true;
$link = $b->directHysteriaLink();
$hy = parse_url($link);
parse_str($hy['query'], $query);
checkDirect443($hy['scheme'] === 'hysteria2' && $hy['host'] === $b->ip && $hy['port'] === 443
    && rawurldecode($hy['user']) === $b->config['hysteria_pass'] && $query === ['sni' => 'eternal.example']);
checkDirect443(rawurldecode($hy['fragment']) === 'Eternal · Hysteria 443');
$tcp = parse_url($b->grpcRealityLink(0));
checkDirect443($tcp['host'] === $b->ip && $tcp['query'] === $legacy['query'] && $tcp['user'] === $legacy['user']);
if (is_file('/docker/compose')) {
    $menu = $b->hysteriaMenu();
    checkDirect443(strpos($menu['text'], htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) !== false);
    $callbacks = array_column(array_merge(...$menu['data']), 'callback_data');
    checkDirect443(!array_diff(['/eternalAutoFile', '/eternalExclave', '/eternalShadowrocket'], $callbacks));
}
$b->input['callback'] = '/eternalAutoFile';
$b->action();
[$chat, $file] = $b->documents[0];
$p = json_decode($file->data, true, 512, JSON_THROW_ON_ERROR);
checkDirect443($chat === 7 && $file->postname === 'Eternal-Auto-Android.json'
    && $p['app'] === 'lxbox' && $p['kind'] === 'backup'
    && !array_intersect(['lx_backup', 'exported_by', 'vpn_settings'], array_keys($p)) && $p['storage']['storage_version'] === 1
    && array_keys($p['storage']) === ['storage_version', 'sources'] && count($p['storage']['sources']) === 1);
$folder = $p['storage']['sources'][0];
checkDirect443($folder['kind'] === 'folder' && $folder['created_at'] === $p['created_at']
    && !isset($folder['replace']) && $folder['tls']['mixed_case_sni'] === false);
$auto = $folder['nodes'][5];
checkDirect443(array_column($folder['nodes'], 'kind') === ['server', 'server', 'server', 'server', 'server', 'auto']
    && $auto['tag'] === 'Eternal · Авто' && $auto['enabled'] && $auto['group']['group_type'] === 'urltest'
    && $auto['group']['strategy']['mode'] === 'least_test' && count($auto['group']['members']) === 5);
foreach ([$b->funnelLink(0, false, '203.0.113.22'), $b->funnelLink(0, false, '203.0.113.23'), $b->funnelLink(0), $link, $b->grpcRealityLink(0)] as $i => $original) {
    checkDirect443(explode('#', $folder['nodes'][$i]['origin']['raw'])[0] === explode('#', $original)[0]);
    checkDirect443($auto['group']['members'][$i] === ['folder_id' => $folder['id'], 'tag' => $folder['nodes'][$i]['tag']]);
}
$unpinned = $b->eternalAutoProfile(false)['storage']['sources'][0];
checkDirect443($unpinned['id'] === $folder['id'] && count($unpinned['nodes']) === 4
    && array_column($unpinned['nodes'][3]['group']['members'], 'tag') === array_column(array_slice($unpinned['nodes'], 0, 3), 'tag'));
checkDirect443(parse_url($folder['nodes'][0]['origin']['raw'], PHP_URL_HOST) === '203.0.113.22'
    && parse_url($folder['nodes'][0]['origin']['raw'], PHP_URL_QUERY) === parse_url($b->funnelLink(0), PHP_URL_QUERY));
foreach ([2, 1, 0] as $edgeCount) {
    $b->edges = array_slice($b->edges, 0, $edgeCount);
    $servers = $b->eternalAutoProfile()['storage']['sources'][0]['nodes'];
    array_pop($servers); // the LxBox-only group
    $names = [];
    foreach ($servers as $server) $names[] = 'Eternal · ' . $server['tag'];
    foreach (['/eternalExclave', '/eternalShadowrocket'] as $client) {
        $b->documents = [];
        $b->input['callback'] = $client;
        $b->action();
        checkDirect443(count($b->documents) === ($client === '/eternalExclave' ? 1 : 2));
        foreach ($b->documents as [$dest, $document, $caption]) {
            checkDirect443($dest === $b->input['from'] && mb_strlen($caption) <= 1024);
        }
        $file = $b->documents[0][1];
        checkDirect443($file->postname === 'Eternal-Nodes.txt');
        $urls = explode("\n", rtrim($file->data, "\n"));
        checkDirect443(count($urls) === count($servers));
        foreach ($urls as $i => $uri) {
            checkDirect443(rawurldecode(parse_url($uri, PHP_URL_FRAGMENT)) === $names[$i]
                && explode('#', $uri, 2)[0] === explode('#', $servers[$i]['origin']['raw'], 2)[0]);
        }
        if ($client === '/eternalShadowrocket') {
            $policy = $b->documents[1][1];
            checkDirect443($policy->postname === 'Eternal-Auto-Shadowrocket.conf'
                && $policy->data === "[Proxy Group]\nEternal · Авто = url-test," . implode(',', $names)
                    . ",interval=60,tolerance=30,timeout=8,url=https://1.1.1.1/cdn-cgi/trace\n\n[Rule]\nFINAL,Eternal · Авто\n");
        }
    }
}
$b->config['eternal_cdn']['uuid'] = 'other';
checkDirect443(!$b->eternalAutoProfile());
$b->documents = [];
$b->action();
checkDirect443(!$b->documents && $b->reply[0] === $b->input['from']);
$b->config['domain'] = $b->ip;
checkDirect443($b->directHysteriaLink() === '');
echo "PASS direct endpoints, LxBox, Exclave links, Shadowrocket policy, owner gating and legacy defaults\n";
