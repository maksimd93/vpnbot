<?php
// Offline regression checks. All credentials are fixtures; Telegram and SSH are mocked.
// Also exercises the original endpoint/import suite against the supplied Bot class.
require __DIR__ . '/test-direct443.php';

class UiTestBot extends Direct443TestBot
{
    private string $preferences;
    public array $xrayFixture;
    public array $menuCalls = [];
    public array $updates = [];
    public array $sentMessages = [];
    public int $statusCalls = 0;

    public function __construct(string $preferences, int $user = 71)
    {
        parent::__construct();
        $this->preferences = $preferences;
        $this->input['from'] = $user;
        $this->input['chat'] = -100;
        $this->input['message_id'] = 8;
        $this->input['callback_id'] = 'fixture-callback';
        $this->config['eternal_direct_443'] = true;
        $this->config['eternal_exit'] = ['enabled' => true,
            'owner_uuid' => $this->config['eternal_cdn']['uuid'],
            'uuid' => '00000000-0000-4000-8000-000000000002'];
        $this->xrayFixture = parent::getXray();
        $this->xrayFixture['inbounds'][0]['settings']['clients'][] = [
            'id' => $this->config['eternal_exit']['uuid'],
            'email' => 'Eternal · WARP-выход', 'eternal_exit' => true];
    }

    protected function uiPreferencesPath(): string { return $this->preferences; }
    public function uiStatusSummary(): string
    {
        ++$this->statusCalls;
        return '<b>📊 Сервисы Eternal</b>' . "\n🟢 VLESS / Xray · ⚪ WARP-прокси";
    }
    public function getXray() { return $this->xrayFixture; }
    public function menu($type = false, $arg = false, $return = false)
    {
        // Classic menu performs infrastructure probes; only dispatch is checked here.
        $this->menuCalls[] = ['type' => $type, 'mode' => $this->uiMode()];
        return $return ? ['fixture menu', []] : null;
    }
    public function update($chat, $message_id, $text, $button = false, $reply = false, $mode = 'HTML')
    {
        $this->updates[] = [$chat, $message_id, $text, $button];
    }
    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false)
    {
        $this->sentMessages[] = [$chat, $text, $to, $button];
        return parent::send($chat, $text, $to, $button, $reply, $mode, $disable_notification);
    }
    public function answer($callback_id, $textNotify = false, $notify = false) { return true; }
    public function setPacConf(array $conf) { throw new RuntimeException('UI attempted to change VPN configuration'); }
    public function restartXray($config, $norestart = false) { throw new RuntimeException('UI attempted to restart Xray'); }
    public function ssh($cmd, $service = 'wg', $wait = true, $log = '/dev/null')
    {
        throw new RuntimeException('Unexpected SSH during offline UI test');
    }
}

class UiMenuIntegrationTestBot extends UiTestBot
{
    public function menu($type = false, $arg = false, $return = false)
    {
        // Invoke the production method while retaining the no-network test fixture.
        return (new ReflectionMethod(Bot::class, 'menu'))->invokeArgs($this, [$type, $arg, $return]);
    }
}

class UiDeliveryTestBot extends UiTestBot
{
    public int $failDocument = 0;
    public bool $failGuide = false;
    public function sendFile($chat, $file, $caption = false, $to = false)
    {
        parent::sendFile($chat, $file, $caption, $to);
        return ['ok' => count($this->documents) !== $this->failDocument];
    }
    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $disable_notification = false)
    {
        parent::send($chat, $text, $to, $button, $reply, $mode, $disable_notification);
        return ['ok' => !$this->failGuide];
    }
}

function uiCheck($ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function uiCallbacks(array $rows): array
{
    return array_column(array_merge(...$rows), 'callback_data');
}

$directory = sys_get_temp_dir() . '/eternal-ui-test-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create private fixture directory');
$path = $directory . '/preferences.json';
try {
    $a = new UiTestBot($path, 71);
    $b = new UiTestBot($path, 72);
    uiCheck($a->uiMode() === 'simple', 'Missing preference defaults to simple UI');
    uiCheck($a->uiSetMode('classic'), 'Classic preference can be saved');
    uiCheck((new UiTestBot($path, 71))->uiMode() === 'classic', 'Rollback survives a fresh request');
    uiCheck($b->uiMode() === 'simple', 'Another user keeps the default');
    uiCheck($b->uiSetMode('classic') && $a->uiSetMode('simple'), 'Independent preference updates');
    uiCheck((new UiTestBot($path, 72))->uiMode() === 'classic'
        && (new UiTestBot($path, 71))->uiMode() === 'simple', 'Updating one user preserves another');
    uiCheck($a->uiSetMode('simple') && $a->uiMode() === 'simple', 'Setting the same mode is idempotent');
    uiCheck(!$a->uiSetMode('invalid') && $a->uiMode() === 'simple', 'Reject unknown interface mode');
    $savedPreferences = file_get_contents($path);
    foreach (['{broken json', 'null', '42', '"simple"', '[]', '{"users":null}'] as $invalid) {
        file_put_contents($path, $invalid);
        uiCheck((new UiTestBot($path, 71))->uiMode() === 'classic', 'Malformed preference fails safely to classic');
        uiCheck(!(new UiTestBot($path, 71))->uiSetMode('simple') && file_get_contents($path) === $invalid,
            'Malformed preferences are preserved for recovery');
    }
    file_put_contents($path, $savedPreferences);

    $a = new UiTestBot($path, 71);
    $originalPac = $a->config;
    $originalXray = $a->xrayFixture;
    $screens = array_map(fn($page) => [$page, 'lxbox', 'auto', 'admin'],
        ['home', 'devices', 'android', 'ios', 'windows', 'help', 'trouble', 'admin', 'protocols']);
    foreach (['devices', 'android', 'ios', 'windows', 'admin'] as $origin) $screens[] = ['protocols', 'lxbox', 'auto', $origin];
    foreach (array_keys($a->uiClients()) as $client) {
        $screens[] = ['modes', $client, 'auto', 'admin'];
        $screens[] = ['guides', $client, 'auto', 'help'];
        foreach (['auto', 'exit'] as $mode) {
            foreach (['setup', 'help'] as $origin) $screens[] = ['guide', $client, $mode, $origin];
        }
    }
    foreach ($screens as [$page, $client, $mode, $origin]) {
        [$text, $rows] = $a->uiScreen($page, $client, $mode, $origin);
        $screenName = "$page/$client/$mode/$origin";
        uiCheck(is_string($text) && trim($text) !== ''
            && mb_strlen(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')) <= 4096, 'Telegram text limit: ' . $screenName);
        uiCheck(is_array($rows) && count($rows) > 0, 'Screen has navigation: ' . $screenName);
        foreach ($rows as $row) {
            uiCheck(is_array($row) && count($row) > 0, 'Nonempty button row');
            foreach ($row as $button) {
                uiCheck(is_string($button['text'] ?? null) && trim($button['text']) !== '', 'Button has a label');
                if (isset($button['callback_data'])) {
                    uiCheck(strlen($button['callback_data']) >= 1 && strlen($button['callback_data']) <= 64,
                        'Telegram callback byte limit: ' . $screenName);
                }
            }
        }
        foreach ($a->xrayFixture['inbounds'][0]['settings']['clients'] as $user) {
            uiCheck(!str_contains($text, $user['id']), 'Navigation does not disclose UUIDs');
        }
        uiCheck(!str_contains($text, $a->config['hysteria_pass']), 'Navigation does not disclose passwords');
    }

    // Follow the buttons as a returning user who already imported either bundle.
    $a->input['callback'] = '/ui help';
    $a->action();
    $helpCallbacks = uiCallbacks(end($a->updates)[3]);
    foreach (array_keys($a->uiClients()) as $client) {
        $chooseMode = '/ui guides ' . $client;
        uiCheck(in_array($chooseMode, $helpCallbacks, true), 'Help asks which imported bundle: ' . $client);
        $a->input['callback'] = $chooseMode;
        $a->action();
        $modeButtons = array_column(array_merge(...end($a->updates)[3]), 'text', 'callback_data');
        foreach (['auto' => 'Eternal · Авто', 'exit' => 'Eternal · WARP-выход'] as $mode => $label) {
            $guideCallback = "/ui guide $client $mode";
            uiCheck(($modeButtons[$guideCallback] ?? null) === '📘 ' . $label, 'Imported mode has its own guide button');
            $a->input['callback'] = $guideCallback;
            $a->action();
            [, , $text, $rows] = end($a->updates);
            uiCheck(str_contains($text, 'Режим: <b>' . $label . '</b>'), 'Guide identifies the selected bundle');
            $callbacks = uiCallbacks($rows);
            uiCheck(in_array("/ui file $client $mode", $callbacks, true)
                && in_array($chooseMode, $callbacks, true), 'Guide preserves mode on download and offers mode selection');
            uiCheck($rows[count($rows) - 2][0]['callback_data'] === $chooseMode
                && $rows[count($rows) - 1][0]['callback_data'] === '/menu', 'Help guide has one-step back and home');
        }
    }
    uiCheck($a->documents === [] && $a->uiMode() === 'simple', 'Reading instructions emits no files or preference changes');

    foreach (array_keys($a->uiClients()) as $client) {
        $a->input['callback'] = '/ui modes ' . $client;
        $a->action();
        [, , $text, $rows] = end($a->updates);
        $expectedBack = '/ui ' . (in_array($client, ['shadowrocket','karing_ios'], true) ? 'ios' : ($client === 'v2rayn' ? 'windows' : 'android'));
        uiCheck($rows[count($rows) - 2][0]['callback_data'] === $expectedBack, 'Modes return to correct device/application step');
        uiCheck(str_contains($text, 'Шаг 3'), 'Connection step follows device and application choice');
    }
    foreach (['devices', 'android', 'ios', 'windows', 'admin'] as $origin) {
        [, $parentRows] = $a->uiScreen($origin);
        uiCheck(in_array('/ui protocols ' . $origin, uiCallbacks($parentRows), true), 'Protocol entry carries its origin');
        $a->input['callback'] = '/ui protocols ' . $origin;
        $a->action();
        $rows = end($a->updates)[3];
        uiCheck($rows[count($rows) - 2][0]['callback_data'] === '/ui ' . $origin, 'Protocol menu returns to its origin');
    }
    $a->input['callback'] = '/ui protocols';
    $a->action();
    $rows = end($a->updates)[3];
    uiCheck($rows[count($rows) - 2][0]['callback_data'] === '/ui admin', 'Legacy protocol callback still returns to admin');

    $integration = new UiMenuIntegrationTestBot($path, 71);
    $home = $integration->uiScreen();
    uiCheck(count($home[1]) === 5 && uiCallbacks($home[1]) === [
        '/ui devices', '/ui help', '/ui admin', '/ui refresh', '/ui set classic'], 'Home exposes primary actions, refresh and rollback');
    uiCheck(str_contains($home[0], '📊 Сервисы Eternal'), 'Home includes service status summary');
    uiCheck($integration->menu(false, false, true) === $home, 'Production menu return contract renders simple home');
    $integration->input['callback_id'] = '';
    foreach (['/start', '/menu'] as $command) {
        $integration->input['message'] = $command;
        $integration->input['callback'] = '';
        $integration->action();
        uiCheck($integration->reply === [-100, $home[0]], 'Production command renders simple home: ' . $command);
    }
    $integration->input['message'] = '';
    $integration->input['callback'] = '/menu';
    $integration->input['callback_id'] = 'fixture-callback';
    $integration->action();
    uiCheck(end($integration->updates) === [-100, 8, $home[0], $home[1]], 'Legacy back callback renders simple home');
    $statusCalls = $integration->statusCalls;
    $integration->input['callback'] = '/ui refresh';
    $integration->action();
    uiCheck($integration->statusCalls === $statusCalls + 1
        && end($integration->updates) === [-100, 8, $home[0], $home[1]], 'Refresh obtains statuses and redraws home');

    foreach (['classic', 'simple', 'simple', 'classic', 'classic', 'simple'] as $mode) {
        $a->input['callback'] = '/ui set ' . $mode;
        uiCheck($a->uiAction() === true && (new UiTestBot($path, 71))->uiMode() === $mode,
            'Explicit switch callback persists: ' . $mode);
    }
    foreach (['/classic' => 'classic', '/newui' => 'simple'] as $command => $mode) {
        $a->input['callback'] = '';
        $a->input['message'] = $command;
        uiCheck($a->uiAction() === true && $a->uiMode() === $mode, 'Recovery command: ' . $command);
    }
    $a->input['message'] = '';

    $a->uiSetMode('classic');
    $a->documents = [];
    $a->input['callback'] = '/ui file lxbox auto';
    $a->uiAction();
    uiCheck($a->documents === [] && $a->uiMode() === 'classic'
        && end($a->menuCalls)['mode'] === 'classic', 'Old messages respect the rollback preference');
    $a->uiSetMode('simple');

    foreach (['auto', 'exit'] as $mode) {
        foreach (array_keys($a->uiClients()) as $client) {
            $a->documents = [];
            $a->sentMessages = [];
            $a->input['callback'] = '/ui file ' . $client . ' ' . $mode;
            $a->action();
            $expected = $mode === 'auto'
                ? ($client === 'lxbox' ? ['Eternal-Auto-Android.json'] : ['Eternal-Nodes.txt'])
                : ($client === 'lxbox' ? ['Eternal-Exit-WARP-Android.json'] : ['Eternal-Exit-WARP-Nodes.txt']);
            if ($client === 'shadowrocket') {
                $expected[] = $mode === 'auto' ? 'Eternal-Auto-Shadowrocket.conf' : 'Eternal-Exit-WARP-Shadowrocket.conf';
            }
            if (in_array($client, ['v2rayn','karing_android','karing_ios'], true)) {
                $suffix = $client === 'v2rayn' ? 'v2rayN' : 'Karing';
                $expected = [($mode === 'auto' ? 'Eternal-Auto-' : 'Eternal-Exit-WARP-') . $suffix . '.txt'];
            }
            uiCheck(array_map(fn($d) => $d[1]->postname, $a->documents) === $expected,
                'Existing generator and filenames: ' . $client . '/' . $mode);
            foreach ($a->documents as [$chat, $file, $caption]) {
                uiCheck($chat === 71 && $chat !== $a->input['chat'], 'Credentials are delivered privately');
                uiCheck(mb_strlen($caption) <= 1024 && strlen($file->data) > 0, 'File content and caption');
            }
            uiCheck(count($a->sentMessages) === 1, 'Exactly one standalone instruction accompanies the files');
            [$guideChat, $guideText, $guideReplyTo, $guideButtons] = $a->sentMessages[0];
            uiCheck($guideChat === 71 && $guideReplyTo === 0 && $guideButtons === false,
                'Forwardable instructions are private, standalone and have no bot-dependent controls');
            uiCheck($guideText === $a->uiClientGuide($client, $mode) && mb_strlen($guideText) <= 3000,
                'Detailed instruction fits one sendMessage without splitting');
            foreach ($expected as $filename) uiCheck(str_contains($guideText, $filename), 'Guide identifies every file: ' . $filename);
            uiCheck(str_contains($guideText, 'https://') && str_contains($guideText, 'https://1.1.1.1/cdn-cgi/trace'), 'Guide includes installation and connection check');
            uiCheck(str_contains($guideText, $mode === 'exit' ? 'warp=on' : 'ip=192.0.2.50'), 'Guide verifies the selected egress');
            $rows = end($a->updates)[3];
            uiCheck($rows[count($rows) - 2][0]['callback_data'] === '/ui modes ' . $client
                && $rows[count($rows) - 1][0]['callback_data'] === '/menu', 'Setup guide returns to mode selection and home');
        }
    }
    $a->documents = []; $a->sentMessages = [];
    $a->input['callback'] = '/ui forward karing_ios exit'; $a->action();
    uiCheck($a->documents === [] && count($a->sentMessages) === 1
        && $a->sentMessages[0][1] === $a->uiClientGuide('karing_ios', 'exit'), 'Forward instruction does not regenerate credentials');
    foreach (['/eternalAutoFile','/eternalExclave','/eternalShadowrocket','/eternalExit lxbox',
        '/eternalExit exclave','/eternalExit shadowrocket','/eternalExit v2rayn','/eternalExit karing_android','/eternalExit karing_ios'] as $callback) {
        $a->documents = []; $a->sentMessages = [];
        $a->input['callback'] = $callback; $a->action();
        uiCheck(count($a->documents) > 0 && count($a->sentMessages) === 1, 'Technical menu delivers complete private kit: ' . $callback);
    }
    $a->uiSetMode('classic');
    foreach (['/ui file v2rayn auto','/ui forward karing_ios exit','/eternalExit v2rayn','/eternalExit karing_android','/eternalExit karing_ios'] as $callback) {
        $a->documents = []; $a->sentMessages = [];
        $a->input['callback'] = $callback; $a->action();
        uiCheck(!$a->documents && !$a->sentMessages && end($a->menuCalls)['mode'] === 'classic', 'Stale client buttons respect classic: ' . $callback);
    }
    $a->uiSetMode('simple');
    foreach (array_keys($a->uiClients()) as $client) foreach (['auto','exit'] as $mode) {
        $failed = new UiDeliveryTestBot($path,71); $failed->failDocument = 1;
        $failed->input['callback'] = "/ui file $client $mode"; $failed->action();
        uiCheck(count($failed->documents) === 1 && !$failed->sentMessages
            && str_contains(end($failed->updates)[2], 'не полностью'), 'Document failure does not claim successful kit delivery');
    }
    $failed = new UiDeliveryTestBot($path,71); $failed->failDocument = 2;
    $failed->input['callback'] = '/ui file shadowrocket exit'; $failed->action();
    uiCheck(count($failed->documents) === 2 && !$failed->sentMessages
        && str_contains(end($failed->updates)[2], 'не полностью'), 'Partial two-file delivery is reported');
    $failed = new UiDeliveryTestBot($path,71); $failed->failGuide = true;
    $failed->input['callback'] = '/ui file karing_ios auto'; $failed->action();
    uiCheck(count($failed->documents) === 1 && count($failed->sentMessages) === 1
        && str_contains(end($failed->updates)[2], 'не полностью'), 'Instruction failure is reported even after file success');
    foreach (['/ui file unknown auto', '/ui file lxbox unknown', '/ui file shadowrocket exit extra', '/ui set unknown'] as $callback) {
        $a->documents = [];
        $a->input['callback'] = $callback;
        $a->uiAction();
        uiCheck($a->documents === [] && $a->uiMode() === 'simple', 'Invalid callback cannot emit files or change interface');
    }
    uiCheck($a->config === $originalPac && $a->xrayFixture === $originalXray, 'UI leaves VPN configuration untouched');
    uiCheck((new UiTestBot($path, 72))->uiMode() === 'classic', 'Other user retains classic after all flows');
    $unavailable = new UiTestBot($path, 71);
    $unavailable->xrayFixture['inbounds'][0]['settings']['clients'][0]['off'] = true;
    foreach (['auto', 'exit'] as $mode) foreach (array_keys($a->uiClients()) as $client) {
        $unavailable->documents = [];
        $unavailable->sentMessages = [];
        $unavailable->input['callback'] = "/ui file $client $mode";
        $unavailable->uiAction();
        uiCheck($unavailable->documents === [] && !$unavailable->sentMessages, 'Disabled owner cannot receive a stale export or success guide: ' . $mode);
    }
    echo "PASS UI preference persistence, rollback, platform navigation, twelve private exports, standalone guides and no VPN mutations\n";
} finally {
    if (is_file($path)) unlink($path);
    rmdir($directory);
}
