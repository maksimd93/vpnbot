<?php
// Offline endpoint generation. All credentials are fixtures.
require $argv[1] ?? __DIR__ . '/../app/bot.php';
class Direct443TestBot extends Bot
{
    public $config;
    public $file;
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
    public function funnelAddresses(string $host): array { return ['203.0.113.22', '203.0.113.23']; }
    public function sendFile($chat, $file, $caption = false, $to = false) { $this->file = [$chat, $file]; }
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
    checkDirect443(in_array('/eternalAutoFile', array_column(array_merge(...$menu['data']), 'callback_data'), true));
}
$b->input['callback'] = '/eternalAutoFile';
$b->action();
[$chat, $file] = $b->file;
$p = json_decode($file->data, true, 512, JSON_THROW_ON_ERROR);
checkDirect443($chat === 7 && $file->postname === 'Eternal-Auto-LxBox.json' && $p['lx_backup'] === 2
    && !array_intersect(['directions', 'route', 'rules', 'vars', 'dns'], array_keys($p)) && count($p['sources']) === 1);
$folder = $p['sources'][0];
checkDirect443($folder['replace']['mode'] === 'auto' && $folder['replace']['tag'] === 'Eternal · Авто'
    && $folder['tls']['mixed_case_sni'] === false && count($folder['nodes']) === 5);
foreach ([$b->funnelLink(0, false, '203.0.113.22'), $b->funnelLink(0, false, '203.0.113.23'), $b->funnelLink(0), $link, $b->grpcRealityLink(0)] as $i => $original) {
    checkDirect443(explode('#', $folder['nodes'][$i]['origin']['raw'])[0] === explode('#', $original)[0]);
}
checkDirect443(parse_url($folder['nodes'][0]['origin']['raw'], PHP_URL_HOST) === '203.0.113.22'
    && parse_url($folder['nodes'][0]['origin']['raw'], PHP_URL_QUERY) === parse_url($b->funnelLink(0), PHP_URL_QUERY));
$b->config['eternal_cdn']['uuid'] = 'other';
checkDirect443(!$b->eternalAutoProfile());
$b->config['domain'] = $b->ip;
checkDirect443($b->directHysteriaLink() === '');
echo "PASS direct Hysteria URI, numeric endpoints, TLS identity and legacy defaults\n";
