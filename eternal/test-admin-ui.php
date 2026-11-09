<?php
// Offline administration UI regression checks. No Telegram, SSH or live configuration writes.
require __DIR__ . '/test-direct443.php';

class AdminUiTestBot extends Direct443TestBot
{
    public string $mode = 'simple';
    public array $xrayFixture;
    public array $statsFixture = ['global' => ['download' => 2000, 'upload' => 300],
        'session' => ['download' => 48, 'upload' => 20],
        'users' => [0 => ['global' => ['download' => 1024, 'upload' => 100]]]];
    public array $screens = [];
    public array $mutations = [];
    public array $destinations = [];

    public function __construct()
    {
        parent::__construct();
        $this->limit = 2;
        $this->input += ['message_id' => 8, 'callback_id' => 'fixture', 'username' => 'fixture'];
        $this->config += ['outbound' => 'client-proxy', 'hwid_device_count' => 2, 'hwid_limit_enabled' => false,
            'ip_limit' => 60, 'ip_count' => 3, 'reset_monthly' => false, 'limitpage' => 2];
        $this->xrayFixture = parent::getXray();
        $this->xrayFixture['inbounds'][0]['settings']['clients'] = [
            0 => ['id' => '00000000-0000-4000-8000-000000000001', 'email' => 'owner <&>', 'time' => 500],
            2 => ['id' => '00000000-0000-4000-8000-000000000002', 'email' => 'disabled', 'off' => true],
            4 => ['id' => '00000000-0000-4000-8000-000000000004', 'email' => 'active-4', 'time' => 300],
            6 => ['id' => '00000000-0000-4000-8000-000000000006', 'email' => 'active-6'],
            8 => ['id' => '00000000-0000-4000-8000-000000000008', 'email' => 'disabled-8', 'off' => true],
        ];
    }
    public function uiMode(): string { return $this->mode; }
    public function getXray() { return $this->xrayFixture; }
    public function getXrayStats() { return $this->statsFixture; }
    public function uiStatusSummary(): string { return 'fixture status'; }
    public function uiShow(array $screen) { $this->screens[] = $screen; }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML') { $this->screens[] = [$text, $button]; }
    public function answer($callback_id, $textNotify = false, $notify = false) { return true; }
    public function menu($type = false, $arg = false, $return = false) { $this->destinations[] = ['menu', $type]; return $return ? ['legacy menu', []] : null; }
    public function xray($page = 0) { $this->destinations[] = ['xray', $page]; }
    public function userXr($i) { $this->destinations[] = ['user', (int) $i]; }
    public function configMenu()
    {
        $callbacks = ['/domain', '/deldomain', '/addNipdomain', '/setSSL letsencrypt', '/deletessl', '/selfssl', '/ports',
            '/export', '/import', '/backup', '/autoupdate', '/menu update', '/restart', '/menu lang', '/enterPage',
            '/addadmin', '/deladmin 73', '/logs', '/ipMenu'];
        return ['text' => 'legacy certificate details', 'data' => array_map(fn($c) => [['text' => 'legacy ' . $c, 'callback_data' => $c]], $callbacks)];
    }
    public function setPacConf(array $conf) { throw new RuntimeException('UI attempted PAC write'); }
    public function setXrayStats($x) { throw new RuntimeException('UI attempted stats write'); }
    public function restartXray($c, $norestart = false) { throw new RuntimeException('UI attempted live Xray restart'); }
    public function ssh($cmd, $service = 'wg', $wait = true, $log = '/dev/null') { throw new RuntimeException('UI attempted SSH'); }
    public function request($method, $data, $json_header = 0) { throw new RuntimeException('UI attempted Telegram delivery'); }
    public function changeTransport($transport) { $this->mutations[] = ['transport', $transport]; }
    public function grpcRealityToggle() { $this->mutations[] = ['grpc']; }
    public function resetXrStats($nomenu = false) { $this->mutations[] = ['stats']; }
    public function resetXrUser($i) { $this->mutations[] = ['user-stats', (int) $i]; }
    public function switchMonthlyStats() { $this->mutations[] = ['monthly']; }
    public function toggleHwidLimit($context = null) { $this->mutations[] = ['hwid', $context]; }
    public function switchXr($i, $nm = 0, $time = false) { $this->mutations[] = ['user-access', (int) $i]; }
    public function delxr($i) { $this->mutations[] = ['delete-user', (int) $i]; }
    public function restart() { $this->mutations[] = ['restart']; }
}

class AdminResetStatsTestBot extends AdminUiTestBot
{
    public int $restartCount = 0;
    public int $statsWrites = 0;
    public function restartXray($c, $norestart = false)
    {
        $this->restartCount++;
        // Production restart collects the final session before resetting it.
        $this->statsFixture['global']['download'] = 9000;
        $this->statsFixture['users'][0]['global']['download'] = 1234;
        $this->statsFixture['users'][2]['global']['download'] = 4567;
        $this->statsFixture['session'] = ['download' => 0, 'upload' => 0];
    }
    public function setXrayStats($x) { $this->statsWrites++; $this->statsFixture = $x; }
}

class AdminLegacyOutputTestBot extends AdminUiTestBot
{
    public array $requests = [];
    public function request($method, $data, $json_header = 0) {
        $this->requests[] = [$method, $data];
        return ['ok' => true, 'result' => ['message_id' => 20]];
    }
    public function getSSConfig() { return ['plugin' => 'v2ray-plugin']; }
}

function adminCheck($ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function adminCallbacks(array $rows): array { return array_column(array_merge(...$rows), 'callback_data'); }
function adminScreenCheck(array $screen, AdminUiTestBot $bot): void
{
    [$text, $rows] = $screen;
    adminCheck(is_string($text) && mb_strlen(strip_tags($text)) <= 4096, 'Screen fits Telegram message');
    adminCheck(!str_contains($text, '\n'), 'Screen does not contain literal escaped line breaks');
    foreach ($rows as $row) foreach ($row as $button) {
        adminCheck(!empty($button['text']), 'Button has a descriptive label');
        if (isset($button['callback_data'])) adminCheck(strlen($button['callback_data']) <= 64, 'Callback fits Telegram limit');
    }
    foreach ($bot->xrayFixture['inbounds'][0]['settings']['clients'] as $user) {
        adminCheck(!str_contains($text, $user['id']), 'Administration overview does not disclose credentials');
    }
    adminCheck(!str_contains($text, $bot->config['hysteria_pass']), 'Administration overview does not disclose Hysteria password');
}
function adminPreview(AdminUiTestBot $b, string $callback): string
{
    $before = $b->mutations;
    $b->input['callback'] = $callback;
    $b->action();
    adminCheck($before === $b->mutations, 'First click previews without changing anything: ' . $callback);
    $screen = end($b->screens);
    adminScreenCheck($screen, $b);
    $buttons = adminCallbacks($screen[1]);
    adminCheck(preg_match('~^/confirmui ([a-f0-9]{24})$~D', $buttons[0] ?? '', $m) === 1, 'Preview has one-use confirmation token');
    adminCheck(($buttons[1] ?? '') === '/cancelui ' . $m[1], 'Preview offers cancellation');
    return $m[1];
}

$previousSession = $_SESSION ?? null;
$_SESSION = [];
try {
    $b = new AdminUiTestBot();
    $pacBefore = $b->config; $xrayBefore = $b->xrayFixture;
    foreach (['main', 'connections', 'limits', 'stats', 'templates'] as $page) {
        adminScreenCheck($b->uiXrayScreen($page), $b);
        $b->input['callback'] = '/xrui ' . $page;
        $b->action();
        adminCheck(end($b->screens) === $b->uiXrayScreen($page), 'Administration page callback: ' . $page);
    }
    foreach (['all', 'on', 'off'] as $filter) {
        for ($page = 0; $page < 4; $page++) adminScreenCheck($b->uiXrayScreen('users', $page, $filter), $b);
    }
    $active = adminCallbacks($b->uiXrayScreen('users', 0, 'on')[1]);
    adminCheck(in_array('/userXr 4', $active, true) && in_array('/userXr 0', $active, true)
        && !in_array('/userXr 2', $active, true), 'Active filter preserves sparse indices and expiry sorting');
    $next = adminCallbacks($b->uiXrayScreen('users', 1, 'on')[1]);
    adminCheck(in_array('/userXr 6', $next, true) && !in_array('/userXr 4', $next, true), 'Next page contains remaining active user');
    $disabled = adminCallbacks($b->uiXrayScreen('users', 0, 'off')[1]);
    adminCheck(in_array('/userXr 2', $disabled, true) && in_array('/userXr 8', $disabled, true), 'Disabled filter is reachable');
    foreach (['/listXr 0' => 'on', '/listXr 1' => 'off'] as $callback => $filter) {
        $b->input['callback'] = $callback; $b->action();
        adminCheck(end($b->screens) === $b->uiXrayScreen('users', 0, $filter), 'Old filters route read-only');
    }
    $connections = adminCallbacks($b->uiXrayScreen('connections')[1]);
    adminCheck(!in_array('/changeTransport xhttp', $connections, true)
        && in_array('/changeTransport Reality', $connections, true), 'Current transport cannot accidentally reapply itself');
    adminCheck(str_contains($b->uiXrayScreen('limits')[0], 'Автоматического отключения пользователя эта настройка не делает'), 'IP limit is described as notification');
    adminCheck(str_contains($b->uiXrayScreen('templates')[0], 'не меняет выходной IP'), 'Client outbound name is not confused with server egress');

    $sectionCallbacks = [
        'network' => ['/domain', '/deldomain', '/addNipdomain', '/setSSL letsencrypt', '/deletessl', '/selfssl', '/ports'],
        'backup' => ['/export', '/import', '/backup'],
        'maintenance' => ['/autoupdate', '/menu update', '/restart'],
        'bot' => ['/menu lang', '/enterPage'], 'access' => ['/addadmin', '/deladmin 73'], 'diagnostics' => ['/logs', '/ipMenu'],
    ];
    foreach (['main', ...array_keys($sectionCallbacks)] as $page) {
        $screen = $b->uiConfigScreen($page); adminScreenCheck($screen, $b);
        if ($page !== 'main') {
            $actual = array_values(array_filter(adminCallbacks($screen[1]), fn($cb) => !in_array($cb, ['/cfgui main', '/menu'], true)));
            adminCheck($actual === $sectionCallbacks[$page], 'Config category keeps exactly its existing controls: ' . $page);
        }
        $b->input['callback'] = '/cfgui ' . $page; $b->action();
        adminCheck(end($b->screens) === $screen, 'Config page callback: ' . $page);
    }
    adminCheck((new ReflectionMethod(Bot::class, 'menu'))->invokeArgs($b, ['config', false, true]) === $b->uiConfigScreen(), 'Production menu config hook renders simple overview');
    (new ReflectionMethod(Bot::class, 'xray'))->invokeArgs($b, [0]);
    adminCheck(end($b->screens) === $b->uiXrayScreen(), 'Production Xray hook renders grouped overview');
    adminCheck($b->config === $pacBefore && $b->xrayFixture === $xrayBefore && $b->mutations === [], 'Navigation leaves VPN state untouched');

    $actions = ['/changeTransport Reality' => ['transport', 'Reality'], '/grpcReality' => ['grpc'],
        '/resetXrStats' => ['stats'], '/resetXrUser 0' => ['user-stats', 0], '/switchMonthlyStats' => ['monthly'],
        '/toggleHwidLimit xray' => ['hwid', 'xray'], '/switchXr 0' => ['user-access', 0], '/delxr 0' => ['delete-user', 0], '/restart' => ['restart']];
    foreach ($actions as $callback => $expected) {
        $token = adminPreview($b, $callback);
        $before = count($b->mutations);
        $b->input['callback'] = '/confirmui ' . $token; $b->action();
        adminCheck(count($b->mutations) === $before + 1 && end($b->mutations) === $expected, 'Confirm dispatches exact original handler: ' . $callback);
        $b->input['callback'] = '/confirmui ' . $token; $b->action();
        adminCheck(count($b->mutations) === $before + 1, 'Confirm token cannot be replayed');
    }
    foreach (['cancel', 'changed', 'user', 'expired', 'classic'] as $condition) {
        $b = new AdminUiTestBot(); $token = adminPreview($b, '/changeTransport Reality');
        if ($condition === 'changed') $b->config['transport'] = 'Websocket';
        if ($condition === 'user') $b->input['from'] = 999;
        if ($condition === 'expired') $_SESSION['ui_review'][$token]['time'] = time() - 301;
        if ($condition === 'classic') $b->mode = 'classic';
        $b->input['callback'] = ($condition === 'cancel' ? '/cancelui ' : '/confirmui ') . $token;
        $b->action();
        adminCheck($b->mutations === [] && empty($_SESSION['ui_review'][$token]), 'No mutation after ' . $condition);
    }
    $b = new AdminUiTestBot(); $b->mode = 'classic';
    $b->input['callback'] = '/xrui connections'; $b->action();
    adminCheck(end($b->destinations) === ['xray', 0], 'Stale new Xray menu respects classic mode');
    $b->input['callback'] = '/cfgui maintenance'; $b->action();
    adminCheck(end($b->destinations) === ['menu', 'config'], 'Stale new config menu respects classic mode');
    $b->input['callback'] = '/changeTransport Reality'; $b->action();
    adminCheck(end($b->mutations) === ['transport', 'Reality'], 'Classic handler behavior is retained');

    $legacy = new AdminLegacyOutputTestBot();
    $credentialText = '<pre>vless://fixture-secret-keep-byte-for-byte</pre>';
    $legacyButtons = [
        [['text' => 'reset stats', 'callback_data' => '/resetXrUser 0'], ['text' => 'on', 'callback_data' => '/switchXr 0']],
        [['text' => 'timer', 'callback_data' => '/timerXr 0'], ['text' => 'delete', 'callback_data' => '/delxr 0']],
        [['text' => 'back', 'callback_data' => '/xray']],
    ];
    $decorated = $legacy->uiLegacyScreen($credentialText, $legacyButtons);
    adminCheck(str_ends_with($decorated[0], $credentialText) && substr_count($decorated[0], 'fixture-secret-keep-byte-for-byte') === 1,
        'Technical screen descriptions preserve existing credential payload exactly once');
    $decoratedCallbacks = adminCallbacks($decorated[1]);
    foreach (['/resetXrUser 0', '/switchXr 0', '/timerXr 0', '/delxr 0'] as $callback) {
        adminCheck(in_array($callback, $decoratedCallbacks, true), 'Decorating does not alter action targets');
    }
    adminCheck(in_array('/xrui users', $decoratedCallbacks, true) && in_array('/menu', $decoratedCallbacks, true), 'User card gets meaningful back and home navigation');
    $long = str_repeat('x', 4090);
    adminCheck($legacy->uiLegacyScreen($long, $legacyButtons)[0] === $long, 'Long credential message is not truncated or enlarged past the Telegram limit');
    $send = new ReflectionMethod(Bot::class, 'send');
    $update = new ReflectionMethod(Bot::class, 'update');
    foreach (['simple', 'classic'] as $mode) {
        $legacy->mode = $mode;
        foreach (['send', 'update'] as $method) {
            if ($method === 'send') $send->invokeArgs($legacy, [1, $credentialText, 0, $legacyButtons]);
            else $update->invokeArgs($legacy, [1, 8, $credentialText, $legacyButtons]);
            [, $data] = end($legacy->requests);
            $expected = $mode === 'simple' ? $legacy->uiFlowScreen($credentialText, $legacyButtons) : [$credentialText, $legacyButtons];
            adminCheck($data['text'] === $expected[0]
                && json_decode($data['reply_markup'], true)['inline_keyboard'] === $expected[1], 'Production ' . $method . ' preserves the chosen interface');
        }
    }
    foreach (['/setIpLimit' => '@fixture enter seconds and count ip', '/mainOutbound' => '@fixture send name',
        '/changeHysteriaPass' => '@fixture enter new password'] as $callback => $oldPrompt) {
        $legacy->input['callback'] = $callback;
        $legacy->mode = 'simple';
        $send->invokeArgs($legacy, [1, $oldPrompt, 0, false, 'enter value']);
        $simple = end($legacy->requests)[1];
        adminCheck($simple['text'] !== $oldPrompt && str_contains($simple['text'], 'Введи'), 'Simple prompt explains expected value and effect');
        $legacy->mode = 'classic';
        $send->invokeArgs($legacy, [1, $oldPrompt, 0, false, 'enter value']);
        adminCheck(end($legacy->requests)[1]['text'] === $oldPrompt, 'Classic prompt remains unchanged');
    }

    $reset = new AdminResetStatsTestBot();
    $xrayBefore = $reset->xrayFixture;
    (new ReflectionMethod(Bot::class, 'resetXrUser'))->invokeArgs($reset, [0]);
    adminCheck($reset->restartCount === 1 && $reset->statsWrites === 1, 'Individual reset persists counters after collecting final session');
    adminCheck(!isset($reset->statsFixture['users'][0]) && $reset->statsFixture['users'][2]['global']['download'] === 4567
        && $reset->statsFixture['global']['download'] === 9000, 'Individual reset preserves other users and fresh global counters');
    adminCheck($reset->xrayFixture === $xrayBefore && end($reset->destinations) === ['user', 0], 'Individual reset preserves accounts and returns to user card');
    echo "PASS grouped administration, read-only filters, confirmation lifecycle, classic fallback and individual stats reset\n";
} finally {
    if ($previousSession === null) unset($_SESSION); else $_SESSION = $previousSession;
}
