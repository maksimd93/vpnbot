<?php
// Offline endpoint generation. All credentials are fixtures.
require $argv[1] ?? __DIR__ . '/../app/bot.php';
class Direct443TestBot extends Bot
{
    public $config;
    public function __construct() {
        $this->ip = '192.0.2.50';
        $this->config = ['domain' => 'eternal.example', 'hysteria_pass' => 'a@b:c &/%',
            'grpc_reality' => ['enabled' => true, 'domain' => 'sni.example', 'service' => 'example',
                'publicKey' => 'TEST', 'shortId' => '12']];
    }
    public function getPacConf() { return $this->config; }
    public function getXray() { return ['inbounds' => [['settings' => ['clients' => [
        ['id' => '00000000-0000-4000-8000-000000000001', 'email' => 'test']]]]]]; }
    public function i18n(string $menu): string { return $menu; }
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
}
$b->config['domain'] = $b->ip;
checkDirect443($b->directHysteriaLink() === '');
echo "PASS direct Hysteria URI, numeric endpoints, TLS identity and legacy defaults\n";
