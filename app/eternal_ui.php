<?php

// Presentation only. Preferences are deliberately separate from VPN configuration.
trait EternalBotUi
{
    protected bool $uiRendering = false;
    protected function uiPreferencesPath(): string { return '/config/bot-ui.json'; }

    public function uiMode(): string
    {
        $path = $this->uiPreferencesPath();
        if (!file_exists($path)) return 'simple';
        $file = @fopen($path, 'r');
        if (!$file) return 'classic';
        try {
            if (!flock($file, LOCK_SH)) return 'classic';
            $data = json_decode(stream_get_contents($file), true);
            if (!is_array($data) || !is_array($data['users'] ?? null)) return 'classic';
            $mode = $data['users'][(string) ($this->input['from'] ?? '')] ?? 'simple';
            return in_array($mode, ['classic', 'simple'], true) ? $mode : 'classic';
        } finally { fclose($file); }
    }

    public function uiSetMode(string $mode): bool
    {
        $id = (string) ($this->input['from'] ?? '');
        if (!in_array($mode, ['simple', 'classic'], true) || !preg_match('/^\d+$/D', $id)) return false;
        $file = @fopen($this->uiPreferencesPath(), 'c+');
        if (!$file) return false;
        try {
            if (!flock($file, LOCK_EX)) return false;
            $raw = stream_get_contents($file);
            $data = $raw === '' ? ['version' => 1, 'users' => []] : json_decode($raw, true);
            if (!is_array($data) || !is_array($data['users'] ?? null)) return false;
            $data['users'][$id] = $mode;
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0)) return false;
            return fwrite($file, $json) === strlen($json) && fflush($file);
        } finally { fclose($file); }
    }

    private function uiButton(string $text, string $callback): array
    {
        return ['text' => $text, 'callback_data' => $callback];
    }

    public function uiClients(): array
    {
        return ['lxbox'=>'LxBox · Android', 'exclave'=>'Exclave · Android',
            'shadowrocket'=>'Shadowrocket · iPhone / iPad', 'v2rayn'=>'v2rayN · Windows',
            'karing_android'=>'Karing · Android', 'karing_ios'=>'Karing · iPhone / iPad'];
    }

    private function uiClientParent(string $client): string
    {
        return match ($client) {
            'shadowrocket', 'karing_ios' => 'ios',
            'v2rayn' => 'windows',
            default => 'android',
        };
    }

    public function uiSendForwardGuide(string $client, string $mode): bool
    {
        if (!isset($this->uiClients()[$client]) || !in_array($mode, ['auto','exit'], true)) return false;
        $previous = $this->uiRendering;
        $this->uiRendering = true;
        try {
            // A new private message can be forwarded without access to bot callbacks.
            $result = $this->send($this->input['from'], $this->uiClientGuide($client, $mode), 0);
            return !empty($result['ok']);
        } finally { $this->uiRendering = $previous; }
    }

    public function uiScreen(string $page = 'home', string $client = 'lxbox', string $mode = 'auto', string $origin = 'admin'): array
    {
        $b = fn($text, $callback) => $this->uiButton($text, $callback);
        $home = [$b('🏠 На главную', '/menu')];
        $apps = $this->uiClients();
        $client = isset($apps[$client]) ? $client : 'lxbox';
        $mode = $mode === 'exit' ? 'exit' : 'auto';
        $group = $mode === 'exit' ? 'Eternal · WARP-выход' : 'Eternal · Авто';
        switch ($page) {
            case 'devices':
                return ["<b>📱 Подключить устройство</b>\nШаг 1 · Устройство\n\nКуда добавить VPN? Дальше выберешь приложение и получишь настройки с инструкцией.", [
                    [$b('📱 Android', '/ui android')],
                    [$b('🍎 iPhone / iPad', '/ui ios')],
                    [$b('💻 Windows', '/ui windows')],
                    [$b('🗂 Другой компьютер или роутер', '/ui protocols devices')], $home]];
            case 'android':
                return ["<b>📱 Подключение · Android</b>\nШаг 2 · Приложение\n\nКакое приложение используешь?", [
                    [$b('📦 LxBox', '/ui modes lxbox')], [$b('📦 Exclave', '/ui modes exclave')],
                    [$b('📦 Karing', '/ui modes karing_android')],
                    [$b('🗂 Другое приложение', '/ui protocols android')], [$b('⬅️ К выбору устройства', '/ui devices')], $home]];
            case 'ios':
                return ["<b>🍎 Подключение · iPhone / iPad</b>\nШаг 2 · Приложение\n\nВыбери клиент. Ссылка на установку будет в инструкции.", [
                    [$b('🚀 Shadowrocket', '/ui modes shadowrocket')], [$b('📦 Karing', '/ui modes karing_ios')],
                    [$b('🗂 Другое приложение', '/ui protocols ios')], [$b('⬅️ К выбору устройства', '/ui devices')], $home]];
            case 'windows':
                return ["<b>💻 Подключение · Windows</b>\nШаг 2 · Приложение\n\nДля v2rayN получишь список узлов и пошаговую инструкцию: установка, импорт, включение прокси или TUN.", [
                    [$b('📦 v2rayN', '/ui modes v2rayn')], [$b('🗂 Другое приложение', '/ui protocols windows')],
                    [$b('⬅️ К выбору устройства', '/ui devices')], $home]];
            case 'modes':
                $step = 3;
                $text = '<b>🔀 ' . $apps[$client] . "</b>\nШаг $step · Режим подключения\n\n"
                    . "<b>Eternal · Авто</b>\nСайты видят адрес Eternal. В наборе есть прямые подключения и резерв.\n\n"
                    . "<b>Eternal · WARP-выход</b>\nСайты видят адрес Cloudflare. WARP работает на сервере, отдельное приложение на телефоне не нужно.\n\n"
                    . 'Кнопка выдаёт файлы и отдельную инструкцию для пересылки. Подключение включается в приложении на устройстве.';
                if ($client === 'v2rayn') $text .= "\n\nv2rayN импортирует отдельные узлы. «Авто» — название набора Eternal; автоматический выбор узла этим TXT не создаётся.";
                $rows = [];
                if ($this->eternalAutoSources(false)) $rows[] = [$b('📥 Получить Eternal · Авто', "/ui file $client auto")];
                if ($this->eternalExitSources(false)) $rows[] = [$b('📥 Получить WARP-выход', "/ui file $client exit")];
                if (!$rows) $text .= "\n\nВыдача готовых наборов сейчас недоступна. Другие подключения есть в управлении сервером.";
                $rows[] = [$b('⬅️ К выбору приложения', '/ui ' . $this->uiClientParent($client))];
                $rows[] = $home;
                return [$text, $rows];
            case 'guide':
                $text = $this->uiClientGuide($client, $mode);
                $back = $origin === 'setup'
                    ? $b('⬅️ К выбору режима', "/ui modes $client")
                    : $b('⬅️ К выбору инструкции', "/ui guides $client");
                return [$text, [[$b('📥 Получить файлы ещё раз', "/ui file $client $mode")],
                    [$b('📤 Инструкция для пересылки', "/ui forward $client $mode")],
                    [['text' => '🌐 Проверить связь в браузере', 'url' => 'https://1.1.1.1/cdn-cgi/trace']],
                    [$b('🛠 Не получается подключиться', '/ui trouble')], [$back], $home]];
            case 'sent':
                return ['<b>📬 Комплект для ' . $apps[$client] . " отправлен</b>\n\nРежим: <b>$group</b>\n\nФайлы и подробная инструкция отправлены в личный чат. Чтобы помочь другому человеку, выдели их вместе и перешли ему. Получателю не нужен доступ к боту.\n\nВ инструкции есть установка, импорт, включение и проверка VPN.", [
                    [$b('📘 Открыть инструкцию', "/ui guide $client $mode")],
                    [$b('📤 Инструкция для пересылки', "/ui forward $client $mode")],
                    [$b('📥 Повторить выдачу файлов', "/ui file $client $mode")],
                    [$b('⬅️ К выбору режима', "/ui modes $client")], $home]];
            case 'delivery_error':
                return ["<b>📭 Комплект отправлен не полностью</b>\n\nTelegram не подтвердил отправку одного из файлов или инструкции. Уже доставленные сообщения сохранятся. Открой личный чат с ботом, нажми Start / «Запустить» и повтори получение комплекта.", [
                    [$b('🔄 Повторить получение', "/ui file $client $mode")],
                    [$b('📘 Открыть инструкцию здесь', "/ui guide $client $mode")],
                    [$b('⬅️ К выбору режима', "/ui modes $client")], $home]];
            case 'guides':
                return ['<b>📘 ' . $apps[$client] . " · Инструкция</b>\n\nКакой набор ты импортировал? Выбери его название, чтобы открыть нужные шаги.", [
                    [$b('📘 Eternal · Авто', "/ui guide $client auto")],
                    [$b('📘 Eternal · WARP-выход', "/ui guide $client exit")],
                    [$b('⬅️ К выбору приложения', '/ui help')], $home]];
            case 'help':
                $rows = [];
                foreach ($apps as $key => $title) $rows[] = [$b('📘 ' . $title, '/ui guides ' . $key)];
                $rows[] = [$b('🛠 Проверка связи и ошибки', '/ui trouble')]; $rows[] = $home;
                return ["<b>📘 Помощь с подключением</b>\n\nВыбери приложение и набор. В инструкции — установка, импорт, включение и проверка. Её можно отправить отдельным сообщением и переслать вместе с файлами.", $rows];
            case 'trouble':
                $ip = htmlspecialchars((string) $this->ip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return ["<b>🛠 Проверка связи</b>\n\nВключи VPN и открой проверку во внешнем браузере этого устройства. Если используешь правила по приложениям, браузер должен входить в VPN.\n"
                    . "• Eternal · Авто: ожидается <code>ip=$ip</code>.\n• WARP-выход: ожидается <code>warp=on</code>; адрес Cloudflare может меняться.\n\n"
                    . "<b>Если интернет не открывается</b>\n1. Проверь, какой узел выбран в приложении.\n"
                    . "2. Внутри нужной папки попробуй «Резерв по IP 1» или «Резерв по IP 2». Они не требуют DNS для поиска входа.\n"
                    . "3. Сравни Wi-Fi и LTE. Запиши название узла и точный текст ошибки.\n\n"
                    . "Файл не включит VPN сам. В LxBox нужно нажать ▶ у узла, в Shadowrocket выбрать маршрутизацию «Конфигурация».", [
                    [['text' => '🌐 Открыть проверку связи', 'url' => 'https://1.1.1.1/cdn-cgi/trace']],
                    [$b('📥 Получить настройки', '/ui devices')], [$b('⬅️ К помощи с подключением', '/ui help')], $home]];
            case 'protocols':
                $rows = [[$b('🛡 VLESS · пользователи и настройки', '/xray')],
                    [$b('🔐 WireGuard / Amnezia · 1', '/changeWG 0')], [$b('🔐 WireGuard / Amnezia · 2', '/changeWG 1')],
                    [$b('🚀 Hysteria', '/menu hy'), $b('🌐 NaiveProxy', '/menu naive')],
                    [$b('🔐 OpenConnect', '/menu oc'), $b('🔐 Shadowsocks', '/menu ss')],
                    [$b('✈️ Прокси для Telegram', '/mtproto')]];
                if (!empty($this->getPacConf()['showdnstt'])) $rows[] = [$b('🌐 DNSTT', '/dnstt')];
                $parents = ['devices' => ['⬅️ К выбору устройства', '/ui devices'],
                    'android' => ['⬅️ К выбору приложения', '/ui android'],
                    'ios' => ['⬅️ К выбору приложения', '/ui ios'],
                    'windows' => ['⬅️ К выбору приложения', '/ui windows'],
                    'admin' => ['⬅️ К управлению сервером', '/ui admin']];
                $rows[] = [$b(...($parents[$origin] ?? $parents['admin']))]; $rows[] = $home;
                return ["<b>🗂 Другие клиенты и протоколы</b>\n\nЗдесь доступны прежние ссылки, QR-коды и настройки. Выбери протокол, который поддерживает твой клиент.\n\nДля LxBox, Exclave, Shadowrocket, v2rayN и Karing получи комплект через «Подключить устройство».", $rows];
            case 'admin':
                return ["<b>⚙️ Управление сервером</b>\n\nТехнические разделы для пользователей, протоколов и маршрутизации. Изменения здесь могут повлиять на подключения.", [
                    [$b('🛡 VLESS · пользователи и настройки', '/xray')], [$b('🗂 Протоколы и другие клиенты', '/ui protocols admin')],
                    [$b('🌐 Маршрутизация', '/routes'), $b('🛡 DNS · AdGuard', '/menu adguard')],
                    [$b('🔗 Подписки и PAC', '/pacMenu 0')], [$b('⚙️ Настройки бота и сервера', '/menu config')],
                    [$b('↩️ Старый интерфейс · все функции', '/ui set classic')], $home]];
            default:
                return ["<b>🛡 Eternal VPN</b>\n\n" . $this->uiStatusSummary() . "\n\nВыбери действие ниже ↓", [
                    [$b('📱 Подключить устройство', '/ui devices')], [$b('📘 Помощь с подключением', '/ui help')],
                    [$b('⚙️ Управление сервером', '/ui admin')], [$b('🔄 Обновить статусы', '/ui refresh')],
                    [$b('↩️ Старый интерфейс', '/ui set classic')]]];
        }
    }

    public function uiShow(array $screen)
    {
        [$text, $buttons] = $screen;
        $previous = $this->uiRendering;
        $this->uiRendering = true;
        try {
            if (!empty($this->input['callback_id'])) {
                return $this->update($this->input['chat'], $this->input['message_id'], $text, $buttons);
            }
            return $this->send($this->input['chat'], $text, $this->input['message_id'] ?? 0, $buttons);
        } finally { $this->uiRendering = $previous; }
    }

    public function uiAction(): bool
    {
        $callback = $this->input['callback'] ?? '';
        $message = $this->input['message'] ?? '';
        $mode = null;
        if ($message === '/classic') $mode = 'classic';
        if ($message === '/newui') $mode = 'simple';
        if (preg_match('~^/ui set (classic|simple)$~D', $callback, $m)) $mode = $m[1];
        if ($mode !== null) {
            if (!$this->uiSetMode($mode)) {
                $this->uiShow(['Не удалось сохранить выбор интерфейса. Попробуй ещё раз.', [[$this->uiButton('🏠 На главную', '/menu')]]]);
            } else { $this->menu(); }
            return true;
        }
        // Technical menus in the new interface use the same complete delivery flow.
        if ($this->uiMode() === 'simple') {
            $legacyClients = ['/eternalAutoFile'=>'lxbox', '/eternalExclave'=>'exclave', '/eternalShadowrocket'=>'shadowrocket'];
            if (isset($legacyClients[$callback])) {
                if (!$this->eternalAutoSources(false)) return false;
                $callback = '/ui file ' . $legacyClients[$callback] . ' auto';
            } elseif (preg_match('~^/eternalExit (lxbox|exclave|shadowrocket|v2rayn|karing_android|karing_ios)$~D', $callback, $m)) {
                if (!$this->eternalExitSources(false) && in_array($m[1], ['lxbox','exclave','shadowrocket'], true)) return false;
                $callback = '/ui file ' . $m[1] . ' exit';
            }
        } elseif (preg_match('~^/eternalExit (v2rayn|karing_android|karing_ios)$~D', $callback)) {
            $this->menu(); return true;
        }
        if (!str_starts_with($callback, '/ui ')) return false;
        // Old messages must not silently undo a user's choice of classic mode.
        if ($this->uiMode() === 'classic') { $this->menu(); return true; }
        if (preg_match('~^/ui (home|refresh|devices|android|ios|windows|help|trouble|admin|protocols)$~D', $callback, $m)) {
            $this->uiShow($this->uiScreen($m[1]));
        } elseif (preg_match('~^/ui protocols (devices|android|ios|windows|admin)$~D', $callback, $m)) {
            $this->uiShow($this->uiScreen('protocols', 'lxbox', 'auto', $m[1]));
        } elseif (preg_match('~^/ui (modes|guides) (lxbox|exclave|shadowrocket|v2rayn|karing_android|karing_ios)$~D', $callback, $m)) {
            $this->uiShow($this->uiScreen($m[1], $m[2]));
        } elseif (preg_match('~^/ui (file|guide|forward) (lxbox|exclave|shadowrocket|v2rayn|karing_android|karing_ios) (auto|exit)$~D', $callback, $m)) {
            [, $action, $client, $mode] = $m;
            if ($action === 'file') {
                $available = $mode === 'exit' ? $this->eternalExitSources(false) : $this->eternalAutoSources(false);
                if (!$available) {
                    $this->uiShow(['Файл этого режима сейчас недоступен. Выбери другой режим.', [
                        [$this->uiButton('⬅️ К выбору режима', "/ui modes $client")], [$this->uiButton('🏠 На главную', '/menu')]]]);
                    return true;
                }
                if (in_array($client, ['v2rayn','karing_android','karing_ios'], true)) {
                    $delivered = $this->sendEternalAdditionalFiles($client, $mode);
                } elseif ($mode === 'exit') $delivered = $this->sendEternalExitFiles($client);
                elseif ($client === 'lxbox') $delivered = $this->sendEternalAutoFile();
                else $delivered = $this->sendEternalClientFiles($client === 'shadowrocket');
                if ($delivered) $delivered = $this->uiSendForwardGuide($client, $mode);
                $this->uiShow($this->uiScreen($delivered ? 'sent' : 'delivery_error', $client, $mode));
            } elseif ($action === 'forward') {
                $delivered = $this->uiSendForwardGuide($client, $mode);
                if ($delivered && !empty($this->input['callback_id'])) $this->answer($this->input['callback_id'], 'Инструкция отправлена в личный чат');
                elseif (!$delivered) $this->uiShow($this->uiScreen('delivery_error', $client, $mode));
            } else {
                $this->uiShow($this->uiScreen('guide', $client, $mode, 'help'));
            }
        } else {
            $this->uiShow($this->uiScreen());
        }
        return true;
    }
}
