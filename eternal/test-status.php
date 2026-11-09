<?php
// Offline service-status regression tests: no Docker, SSH or Telegram requests.
require $argv[1] ?? __DIR__ . '/../app/bot.php';

class StatusTestBot extends Bot
{
    public array $responses = [];
    public array $batches = [];
    public array $config = ['amnezia' => true, 'wg1_amnezia' => false];

    public function __construct() {}
    public function getPacConf() { return $this->config; }
    protected function uiStatusRequests(array $paths): array
    {
        $this->batches[] = $paths;
        $result = [];
        foreach ($paths as $path) {
            statusCheck($path === '/containers/json?all=1'
                || preg_match('~^/containers/[a-f0-9]{64}/top\?ps_args=-o%20pid%2Ccomm$~D', $path),
                'Status uses only the expected read-only Docker endpoints');
            $result[$path] = $this->responses[$path] ?? null;
        }
        return $result;
    }
    public function ssh($cmd, $service = 'wg', $wait = true, $log = '/dev/null')
    {
        throw new RuntimeException('Status attempted SSH');
    }
    public function dockerApi($url, $method = 'GET', $data = [])
    {
        throw new RuntimeException('Status bypassed the bounded read-only request adapter');
    }
    public function setPacConf(array $conf) { throw new RuntimeException('Status attempted configuration mutation'); }
    public function request($method, $data, $json_header = 0) { throw new RuntimeException('Status attempted Telegram delivery'); }
}

function statusCheck($ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function statusTopPath(string $key): string
{
    return '/containers/' . hash('sha256', $key) . '/top?ps_args=-o%20pid%2Ccomm';
}

function statusFixture(): StatusTestBot
{
    $definitions = [
        'wg' => ['wireguard', 'amneziawg-go'], 'wg1' => ['wireguard1', 'wireguard-go'],
        'xr' => ['xray', 'xray'], 'hy' => ['hysteria', 'hysteria'],
        'np' => ['naive', 'caddy'], 'oc' => ['openconnect', 'ocserv'],
        'ss' => ['shadowsocks', 'ssserver'], 'tg' => ['mtproto', 'mtproto-proxy'],
        'ad' => ['adguard', 'AdGuardHome'], 'dnstt' => ['dnstt', 'dnstt-server'],
        'wp' => ['warp', 'warp-svc'], 'egress' => ['eternal-egress', 'xray'],
        'up' => ['upstream', 'nginx'], 'ng' => ['nginx', 'nginx'],
        'php' => ['php', 'unitd'], 'proxy' => ['proxy', 'sslocal'], 'service' => ['service', 'php'],
    ];
    $b = new StatusTestBot();
    $containers = [];
    foreach ($definitions as $key => [$name, $command]) {
        $containers[] = ['Id' => hash('sha256', $key),
            'Names' => ['/' . $name . ($key === 'egress' ? '' : '-2.29')],
            'State' => 'running', 'Status' => 'Up 2 hours'];
        $b->responses[statusTopPath($key)] = ['Titles' => ['PID', 'COMMAND'],
            'Processes' => [['100', 'sh'], ['101', 'sshd'], ['102', $command]]];
    }
    $b->responses['/containers/json?all=1'] = $containers;
    return $b;
}

function statusChangeContainer(StatusTestBot $bot, string $key, array $changes): void
{
    foreach ($bot->responses['/containers/json?all=1'] as &$container) {
        if ($container['Id'] === hash('sha256', $key)) $container = array_replace($container, $changes);
    }
}

$previousVersion = getenv('VER');
putenv('VER=2.29');
try {
    $b = statusFixture();
    $keys = ['wg', 'wg1', 'xr', 'hy', 'np', 'oc', 'ss', 'tg', 'ad', 'dnstt', 'wp', 'egress', 'up', 'ng', 'php', 'proxy', 'service'];
    $states = $b->uiServiceStates();
    statusCheck(count($states) === count($keys) && !array_diff($keys, array_keys($states)), 'All VPN and supporting services are represented');
    statusCheck(count(array_unique($states)) === 1 && reset($states) === 'on', 'Detected service processes are on');
    statusCheck(count($b->batches) === 2 && count($b->batches[1]) === count($keys), 'Snapshot and process probes are batched');

    $b->responses[statusTopPath('oc')]['Processes'] = [['100', 'ocserv-main'], ['101', 'ocserv-sm']];
    statusCheck($b->uiServiceStates()['oc'] === 'on', 'OpenConnect main process title is recognized');

    $b = statusFixture();
    $b->responses[statusTopPath('wp')]['Processes'] = [['100', 'sh'], ['101', 'sshd'], ['102', 'socat']];
    $states = $b->uiServiceStates();
    statusCheck($states['wp'] === 'off' && $states['egress'] === 'on', 'Running old WARP container is not mistaken for the active server WARP exit');

    $b = statusFixture();
    $b->responses[statusTopPath('xr')]['Processes'] = [['100', 'sh'], ['101', 'xray-helper']];
    $b->responses[statusTopPath('wg')]['Processes'] = [['100', 'sh'], ['101', 'sshd']];
    $states = $b->uiServiceStates();
    statusCheck($states['xr'] === 'off', 'Process matching does not accept an unrelated command prefix');
    statusCheck($states['wg'] === 'unknown', 'Kernel WireGuard is not falsely reported off from process absence');

    foreach (['paused', 'restarting'] as $state) {
        $b = statusFixture();
        statusChangeContainer($b, 'xr', ['State' => $state]);
        statusCheck($b->uiServiceStates()['xr'] === 'warning', 'Container transition or pause is not green: ' . $state);
        statusCheck(!in_array(statusTopPath('xr'), array_merge(...$b->batches), true), 'Paused/restarting container is not queried for processes');
    }
    foreach (['exited', 'dead'] as $state) {
        $b = statusFixture();
        statusChangeContainer($b, 'xr', ['State' => $state]);
        statusCheck($b->uiServiceStates()['xr'] === 'off', 'Stopped container is off: ' . $state);
    }
    $b = statusFixture();
    statusChangeContainer($b, 'xr', ['Status' => 'Up 1 hour (unhealthy)']);
    statusCheck($b->uiServiceStates()['xr'] === 'warning', 'Unhealthy container is not green despite a running process');
    foreach ([['State' => []], ['Status' => []], ['Id' => []], ['Id' => '../unrelated']] as $badFields) {
        $b = statusFixture();
        statusChangeContainer($b, 'xr', $badFields);
        statusCheck($b->uiServiceStates()['xr'] === 'unknown', 'Malformed container fields are unknown without constructing an unsafe request');
    }

    foreach ([null, ['message' => 'Docker unavailable']] as $snapshot) {
        $b = statusFixture();
        $b->responses['/containers/json?all=1'] = $snapshot;
        $states = $b->uiServiceStates();
        statusCheck(count($states) === count($keys) && array_values(array_unique($states)) === ['unknown'], 'Unavailable or malformed snapshot leaves all statuses unknown');
        statusCheck(count($b->batches) === 1, 'Failed inventory does not trigger process probes');
    }
    $b = statusFixture();
    $b->responses['/containers/json?all=1'] = [];
    statusCheck(array_values(array_unique($b->uiServiceStates())) === ['missing'], 'Successful empty inventory reports missing containers');

    foreach ([null, ['message' => 'Process list unavailable'], ['Titles' => ['PID'], 'Processes' => [['1']]],
        ['Titles' => ['PID', 'COMMAND'], 'Processes' => 'malformed'],
        ['Titles' => ['PID', 'COMMAND'], 'Processes' => [['1', 'xray'], ['2']]]] as $top) {
        $b = statusFixture();
        $b->responses[statusTopPath('xr')] = $top;
        statusCheck($b->uiServiceStates()['xr'] === 'unknown', 'Failed or malformed process list is unknown, not off');
    }
    $b = statusFixture();
    $b->responses[statusTopPath('xr')] = ['Titles' => ['COMMAND', 'PID'], 'Processes' => [['xray', '100']]];
    statusCheck($b->uiServiceStates()['xr'] === 'on', 'Process column is located by title');

    $b = statusFixture();
    $b->responses['/containers/json?all=1'] = [
        ['Id' => hash('sha256', 'unrelated-xray'), 'Names' => ['/xray-9.99'], 'State' => 'running', 'Status' => 'Up 1 hour'],
        ['Id' => hash('sha256', 'unrelated-vault'), 'Names' => ['/vaultwarden'], 'State' => 'running', 'Status' => 'Up 1 hour'],
    ];
    statusCheck(array_values(array_unique($b->uiServiceStates())) === ['missing'], 'Unrelated applications and other bot versions are excluded');
    statusCheck(count($b->batches) === 1, 'Unrelated containers are not queried');

    $b = statusFixture();
    $before = $b->config;
    $text = $b->uiStatusSummary();
    statusCheck(is_string($text) && mb_strlen(strip_tags($text)) < 3000, 'Status summary fits inside the Telegram home message');
    statusCheck(str_contains($text, 'WARP') && str_contains($text, 'Hysteria'), 'Status summary identifies key VPN services');
    statusCheck($b->config === $before, 'Status summary does not mutate VPN configuration');
    echo "PASS service process states, old/new WARP distinction, failure handling, scoped read-only probes and summary limits\n";
} finally {
    putenv($previousVersion === false ? 'VER' : 'VER=' . $previousVersion);
}
