<?php

// Presentation adapter for the existing technical screens. Callbacks keep their
// existing handlers; confirmations are applied separately by the admin UI.
trait EternalLegacyUi
{
    public function uiLegacyScreen($text, $buttons): array
    {
        if (!is_string($text) || !is_array($buttons) || !$buttons) return [$text, $buttons];
        $callbacks = [];
        foreach ($buttons as $row) foreach (is_array($row) ? $row : [] as $button) {
            if (is_array($button) && is_string($button['callback_data'] ?? null)) $callbacks[] = $button['callback_data'];
        }
        $has = fn(string $pattern): bool => (bool) preg_grep($pattern, $callbacks);
        $section = match (true) {
            $has('~^/resetXrUser \d+$~D') => 'user',
            $has('~^/hwidUserToggle \d+$~D') => 'hwid',
            $has('~^/templateAdd (v2ray|sing|clash)$~D') => 'templates',
            $has('~^/choiceTemplate ~') => 'user-template',
            $has('~^/changeHysteriaPass$~D') => 'hysteria',
            $has('~^/changeNaiveUser$~D') => 'naive',
            $has('~^/changeOcExpose$~D') => 'openconnect',
            $has('~^/sspswd$~D') => 'shadowsocks',
            $has('~^/adguardreset$~D') => 'adguard',
            $has('~^/switchAmnezia ~') => 'wireguard',
            $has('~^/hidePort ~') => 'ports',
            $has('~^/xtlsblock$~D') => 'routes',
            $has('~^/include (blocklist|warplist|includelist|packagelist|processlist|subnetlist|rulessetlist)$~D') => 'route-list',
            default => '',
        };
        if ($section === '') return [$text, $buttons];
        $intro = [
            'user' => "<b>👤 Пользователь VLESS</b>\nСсылки и QR-коды подключают этот профиль. Кнопки «Отключить» и «Удалить» управляют его доступом; таймер задаёт срок действия.",
            'hwid' => "<b>📱 Устройства по подписке</b>\nHWID проверяется при загрузке подписки. Нужен клиент с поддержкой HWID; это не подсчёт активных VPN-туннелей. Удаление записи освобождает место для устройства.",
            'templates' => "<b>🧩 Шаблоны клиентских конфигураций</b>\nШаблон задаёт содержимое выдаваемых файлов. «По умолчанию» применяется к пользователям без личного шаблона. Ниже перечислены доступные подстановки.",
            'user-template' => "<b>🧩 Шаблон этого пользователя</b>\nОбщий шаблон наследует выбор для всех пользователей. Встроенный шаблон использует исходный формат бота. Личный выбор применяется при следующем получении конфигурации.",
            'hysteria' => "<b>🚀 Hysteria · подключение и пароль</b>\nНиже данные для импорта. Смена общего пароля потребует обновить Hysteria на устройствах. Готовые наборы Eternal доступны также через «Подключить устройство».",
            'naive' => "<b>🌐 NaiveProxy · данные подключения</b>\nСсылка содержит адрес, логин и пароль. Изменение любого из них потребует обновить настройки клиента.",
            'openconnect' => "<b>🔐 OpenConnect · подключение и пользователи</b>\nПоддомен задаёт адрес входа; секрет маскировки входит в URL. Пароль общий для учётных записей. DNS и подсети задают параметры этого VPN.",
            'shadowsocks' => "<b>🔐 Shadowsocks · подключение</b>\nСсылка и QR-код содержат текущие параметры. Плагин v2ray добавляет WebSocket; его переключение и смена пароля требуют обновить настройки клиента.",
            'adguard' => "<b>🛡 DNS · AdGuard Home</b>\nЗдесь управление DNS-сервером: панель, доступ клиентов и вышестоящие DNS. Сброс вернёт настройки из шаблона бота и перезапустит DNS.",
            'wireguard' => "<b>🔐 WireGuard / Amnezia · выбранный сервер</b>\nСписок ниже показывает устройства. Amnezia и AWG меняют формат протокола; смена режима или ключей маскировки потребует заново выдать профили. DNS и MTU по умолчанию применяются к новым профилям.",
            'ports' => "<b>🔌 Публичные порты</b>\nКнопки меняют публикацию портов в Docker. Изменения вступают в силу после перезапуска; закрытие порта отключит вход через него.",
            'routes' => "<b>🌐 Правила маршрутизации</b>\nВыбери, по чему задавать правило: домен, IP-подсеть или приложение. Эти списки используются в выдаваемых конфигурациях; поддержка зависит от клиента и шаблона.",
            'route-list' => "<b>🌐 Список правил</b>\nНажатие на запись включает или выключает её; удаление убирает правило. Изменения учитываются при следующем получении конфигурации.",
        ][$section];
        $p = $this->getPacConf();
        $prefix = !empty($p['wg_instance']) ? 'wg1_' : '';
        $fixed = [
            '/changeHysteriaPass' => '🔑 Изменить общий пароль Hysteria',
            '/eternalAutoFile' => '📥 LxBox · готовый набор Eternal',
            '/eternalExclave' => '📥 Exclave · готовый набор Eternal',
            '/eternalShadowrocket' => '📥 Shadowrocket · готовый набор Eternal',
            '/eternalExitMenu' => '☁️ Набор с выходом через WARP',
            '/changeNaiveSubdomain' => '🌐 Изменить адрес NaiveProxy',
            '/changeNaiveUser' => '👤 Изменить логин NaiveProxy',
            '/changeNaivePass' => '🔑 Изменить пароль NaiveProxy',
            '/changeOcDomain' => '🌐 Изменить поддомен OpenConnect',
            '/changeCamouflage' => '🔐 Изменить секрет в URL',
            '/changeOcPass' => '🔑 Изменить общий пароль',
            '/changeOcDns' => '✏️ Изменить DNS для OpenConnect',
            '/addOcUser' => '➕ Добавить пользователя OpenConnect',
            '/sspswd' => '🔑 Изменить пароль Shadowsocks',
            '/qrSS' => '📷 QR-код подключения',
            '/adguardpsswd' => '🔑 Изменить пароль панели DNS',
            '/setAdguardKey' => '🔐 Изменить ClientID для DNS',
            '/adgFillAllowedClients 0' => '🔒 Разрешить DNS только клиентам VPN',
            '/adgFillAllowedClients 1' => '🔓 Снять ограничение клиентов DNS',
            '/checkdns' => '🔎 Проверить ответы DNS',
            '/adguardreset' => '♻️ Сбросить настройки AdGuard',
            '/addupstream' => '➕ Добавить вышестоящий DNS',
            '/xtlsblock' => '🚫 Домены для блокировки',
            '/xtlswarp' => '☁️ Домены через WARP-прокси',
            '/xtlsproxy' => '🌐 Правила по доменам',
            '/xtlssubnet' => '📍 Правила по IP-подсетям',
            '/xtlsprocess' => '💻 Правила по процессам',
            '/xtlsapp' => '📱 Правила по Android-приложениям',
            '/xtlsrulesset' => '📚 Наборы правил по ссылке',
            '/restart' => '🔄 Перезапустить службы бота',
            '/routes' => '⬅️ К видам правил',
            '/menu config' => '⬅️ К настройкам сервера',
        ];
        $newRows = [];
        foreach ($buttons as $row) {
            $newRow = [];
            foreach ($row as $button) {
                $cb = $button['callback_data'] ?? '';
                $old = (string) ($button['text'] ?? '');
                if (isset($fixed[$cb])) $button['text'] = $fixed[$cb];
                elseif ($cb === '/menu') $button['text'] = '🏠 На главную';
                elseif ($cb === '/xray') {
                    $button['callback_data'] = $section === 'user' ? '/xrui users' : '/xrui main';
                    $button['text'] = $section === 'user' ? '⬅️ К пользователям VLESS' : '⬅️ К управлению VLESS';
                } elseif ($cb === '/adguardChBr') {
                    $button['text'] = !empty($p['adgbrowser']) ? '🔒 Запретить вход через внешний браузер' : '🌐 Разрешить вход через внешний браузер';
                } elseif ($cb === '/v2ray') {
                    $button['text'] = !empty($this->getSSConfig()['plugin']) ? 'Выключить WebSocket-плагин' : 'Включить WebSocket-плагин';
                } elseif ($cb === '/changeOcExpose') {
                    $enabled = str_ends_with(trim($old), $this->i18n('on'));
                    $button['text'] = $enabled ? 'Не передавать маршруты клиентов' : 'Передавать маршруты клиентов';
                } elseif (preg_match('~^/deloc (\d+)$~D', $cb)) $button['text'] = '🗑 Удалить пользователя: ' . preg_replace('~^\S+\s+~u', '', $old);
                elseif (preg_match('~^/delupstream (\d+)$~D', $cb)) $button['text'] = '🗑 Удалить этот DNS';
                elseif (preg_match('~^/hidePort (\w+)$~D', $cb)) {
                    $enabled = str_starts_with(trim($old), $this->i18n('on'));
                    $button['text'] = ($enabled ? '🔒 Закрыть порт: ' : '🔓 Открыть порт: ') . preg_replace('~^\S+\s+~u', '', $old);
                }
                elseif ($cb === '/changePort hy') $button['text'] = '✏️ Изменить внешний порт Hysteria';
                elseif (preg_match('~^/resetXrUser (\d+)$~D', $cb)) {
                    $button['text'] = '♻️ Сбросить счётчики';
                    if (preg_match('~(↓.+)$~u', $old, $usage)) $intro .= "\nТрафик: " . htmlspecialchars($usage[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                elseif (preg_match('~^/switchXr (\d+)$~D', $cb, $m)) {
                    $user = $this->getXray()['inbounds'][0]['settings']['clients'][(int) $m[1]] ?? [];
                    $button['text'] = !empty($user['off']) ? '▶️ Включить доступ пользователя' : '⏸ Отключить доступ пользователя';
                } elseif (preg_match('~^/timerXr (\d+)$~D', $cb, $m)) {
                    $expires = (int) ($this->getXray()['inbounds'][0]['settings']['clients'][(int) $m[1]]['time'] ?? 0);
                    $intro .= "\nСрок доступа: " . ($expires ? date('d.m.Y H:i', $expires) : 'без ограничения');
                    $button['text'] = '⏳ Изменить срок действия доступа';
                }
                elseif (preg_match('~^/renameXrUser \d+$~D', $cb)) $button['text'] = '✏️ Переименовать пользователя';
                elseif (preg_match('~^/delxr \d+$~D', $cb)) $button['text'] = '🗑 Удалить пользователя';
                elseif (preg_match('~^/hwidUser \d+$~D', $cb)) $button['text'] = '📱 Устройства и лимит подписки';
                elseif (preg_match('~^/funnelMenu \d+$~D', $cb)) $button['text'] = '🛟 Резервный вход через Funnel';
                elseif (preg_match('~^/warpMenu \d+$~D', $cb)) $button['text'] = '☁️ Клиентская цепочка через WARP';
                elseif (preg_match('~^/cdnMenu \d+$~D', $cb)) $button['text'] = '🌐 Дополнительный вход через CDN';
                elseif (preg_match('~^/dw \d+ (s|si|cl)$~D', $cb, $m)) $button['text'] = '📥 Файл ' . ['s'=>'Xray', 'si'=>'sing-box', 'cl'=>'Mihomo'][$m[1]];
                elseif (preg_match('~^/templateUser (v2ray|sing|clash) \d+$~D', $cb, $m)) $button['text'] = '🧩 Шаблон ' . ['v2ray'=>'Xray', 'sing'=>'sing-box', 'clash'=>'Mihomo'][$m[1]];
                elseif (preg_match('~^/qrXray \d+(?:_([123]))?$~D', $cb, $m)) $button['text'] = '📷 QR-код ' . [0=>'VLESS', 1=>'Xray', 2=>'sing-box', 3=>'gRPC REALITY'][(int) ($m[1] ?? 0)];
                elseif (preg_match('~^/hwidUserToggle (\d+)$~D', $cb, $m)) {
                    $user = $this->getXray()['inbounds'][0]['settings']['clients'][(int) $m[1]] ?? [];
                    $button['text'] = !empty($user['hwid_disabled']) ? '🔒 Включить личную проверку HWID' : '🔓 Отключить личную проверку HWID';
                } elseif (preg_match('~^/setHwidUserLimit \d+$~D', $cb)) $button['text'] = '✏️ Задать личный лимит устройств';
                elseif (preg_match('~^/hwidUserDefault \d+$~D', $cb)) $button['text'] = '↩️ Использовать общий лимит';
                elseif (preg_match('~^/hwidUserDel ~', $cb)) $button['text'] = '🗑 Забыть устройство ' . preg_replace('~^\D+~u', '', $old);
                elseif (preg_match('~^/userXr \d+$~D', $cb)) $button['text'] = '⬅️ К пользователю';
                elseif (preg_match('~^/switchAmnezia ~', $cb)) $button['text'] = !empty($p[$prefix . 'amnezia']) ? 'Перейти с Amnezia на WireGuard' : 'Перейти с WireGuard на Amnezia';
                elseif (preg_match('~^/switchAwg3 ~', $cb)) $button['text'] = !empty($p[$prefix . 'amnezia']) && !empty($p[$prefix . 'awg3']) ? 'Выключить режим AWG 3.1' : 'Включить режим AWG 3.1';
                elseif (preg_match('~^/resetAmnezia ~', $cb)) $button['text'] = '🔑 Пересоздать ключи маскировки';
                elseif (preg_match('~^/switchTorrent ~', $cb)) $button['text'] = !empty($p[$prefix . 'blocktorrent']) ? 'Разрешить торренты' : 'Блокировать торренты';
                elseif (preg_match('~^/switchExchange ~', $cb)) $button['text'] = !empty($p[$prefix . 'exchange']) ? 'Разрешить связь между клиентами' : 'Запретить связь между клиентами';
                elseif (preg_match('~^/switchEndpoint ~', $cb)) $button['text'] = !empty($p[$prefix . 'endpoint']) ? 'Использовать домен в профилях' : 'Использовать IP в профилях';
                elseif (preg_match('~^/defaultDNS ~', $cb)) $button['text'] = '✏️ DNS для новых профилей';
                elseif (preg_match('~^/defaultMTU ~', $cb)) $button['text'] = '✏️ MTU для новых профилей';
                elseif (preg_match('~^/subnet ~', $cb)) $button['text'] = '📍 Подсети в конфигурации';
                elseif (preg_match('~^/menu addpeer ~', $cb)) $button['text'] = '➕ Добавить устройство';
                elseif (preg_match('~^/menu wg ~', $cb)) $button['text'] = '🔄 Обновить состояние устройств';
                elseif (preg_match('~^/templateAdd ~', $cb)) $button['text'] = '📤 Загрузить свой шаблон';
                elseif (preg_match('~^/downloadOrigin ~', $cb)) $button['text'] = '📥 Скачать встроенный шаблон';
                elseif (preg_match('~^/templateCopy ~', $cb)) $button['text'] = '📋 Скопировать встроенный шаблон';
                elseif (preg_match('~^/downloadTemplate ~', $cb)) $button['text'] = '📥 Скачать этот шаблон';
                elseif (preg_match('~^/delTemplate ~', $cb)) $button['text'] = '🗑 Удалить этот шаблон';
                elseif (preg_match('~^/defaultTemplate (v2ray|sing|clash)(?: (\S+))?$~D', $cb, $m)) {
                    $selected = ($p['default' . $m[1] . 'template'] ?? '') === ($m[2] ?? '');
                    $button['text'] = $selected ? '✅ Сейчас по умолчанию' : 'Сделать шаблоном по умолчанию';
                } elseif (preg_match('~^/choiceTemplate (v2ray|sing|clash)_\d+(?:_(.+))?$~D', $cb, $m)) {
                    $button['text'] = empty($m[2]) ? '↩️ Использовать общий шаблон' : ($m[2] === base64_encode('origin') ? '🧩 Использовать встроенный шаблон' : '🧩 Выбрать: ' . $old);
                } elseif (preg_match('~^/include ~', $cb)) $button['text'] = '➕ Добавить запись';
                elseif (preg_match('~^/deleteAll ~', $cb)) $button['text'] = '🗑 Очистить весь список';
                elseif (preg_match('~^/exportList ~', $cb)) $button['text'] = '📥 Скачать список';
                elseif (preg_match('~^/importList ~', $cb)) $button['text'] = '📤 Загрузить список';
                elseif (preg_match('~^/delete\w+list ~', $cb)) $button['text'] = '🗑 Удалить запись';
                elseif (preg_match('~^/change(\w+list) (\d+) \d+$~D', $cb, $m)) {
                    $entries = array_values($p[$m[1]] ?? []);
                    $name = preg_replace('~^[🟢🔴✅❌]\s*~u', '', $old);
                    $button['text'] = (!empty($entries[(int) $m[2]]) ? 'Выключить: ' : 'Включить: ') . $name;
                }
                if (isset($button['web_app']) && $section === 'user') $button['text'] = '👁 Конфиг ' . $old;
                elseif (isset($button['web_app']) && $section === 'adguard') $button['text'] = '🌐 Открыть панель AdGuard';
                elseif (isset($button['web_app']) && $section === 'templates' && $old === 'origin') $button['text'] = '👁 Встроенный шаблон';
                $newRow[] = $button;
            }
            // Keep long actions readable instead of compressing three labels.
            $width = array_sum(array_map(fn($b) => mb_strlen((string) ($b['text'] ?? '')), $newRow));
            if (count($newRow) > 1 && $width > 43) foreach ($newRow as $button) $newRows[] = [$button];
            else $newRows[] = $newRow;
        }
        if (!in_array('/menu', $callbacks, true)) $newRows[] = [['text'=>'🏠 На главную', 'callback_data'=>'/menu']];
        $combined = $intro . "\n\n" . $text;
        // Never truncate credentials or turn an existing valid message oversized.
        if (mb_strlen(html_entity_decode(strip_tags($combined), ENT_QUOTES, 'UTF-8')) <= 4096) $text = $combined;
        return [$text, $newRows];
    }

    public function uiLegacyPrompt(string $text): string
    {
        $callback = (string) ($this->input['callback'] ?? '');
        $prompts = [
            '/setIpLimit' => 'Введи интервал в секундах и число IP через двоеточие, например 60:2. При превышении бот уведомит администратора; автоматического отключения нет. Для выключения введи 0.',
            '/mainOutbound' => 'Введи имя основного выхода в выдаваемых клиентских конфигурациях. Это название тега, а не переключение выхода сервера или WARP.',
            '/addLinkDomain' => 'Введи домен для адреса в выдаваемых ссылках. Он должен уже вести к нужному входу; эта настройка не создаёт CDN или DNS-запись.',
            '/grpcRealityDomain' => 'Введи домен SNI для дополнительного входа gRPC REALITY. После сохранения обновятся настройки сервера; клиентам понадобится новое значение SNI.',
            '/changeFakeDomain' => 'Введи домен маскировки основного входа REALITY. После сохранения обнови SNI в клиентах; текущие подключения могут прерваться.',
            '/changeHysteriaPass' => 'Введи новый общий пароль Hysteria. После сохранения служба перезапустится; обнови пароль во всех использующих её профилях.',
            '/changeNaiveUser' => 'Введи новый логин NaiveProxy. После сохранения обнови логин в клиентах.',
            '/changeNaivePass' => 'Введи новый пароль NaiveProxy. После сохранения обнови пароль в клиентах.',
            '/changeNaiveSubdomain' => 'Введи новый поддомен NaiveProxy. Адрес сервера в клиентах изменится.',
            '/changeOcDomain' => 'Введи новый поддомен OpenConnect. Адрес сервера в клиентах изменится.',
            '/changeOcPass' => 'Введи новый общий пароль OpenConnect. После сохранения обнови пароль у пользователей этого протокола.',
            '/changeOcDns' => 'Введи адрес DNS для клиентов OpenConnect. Применение перезапустит службу OpenConnect.',
            '/changeCamouflage' => 'Введи новый секрет маскировки OpenConnect. Он входит в URL подключения, поэтому обнови адрес в клиентах.',
            '/sspswd' => 'Введи новый пароль Shadowsocks. После сохранения перезапустятся Shadowsocks и локальный прокси; обнови пароль на устройствах.',
        ];
        if (isset($prompts[$callback]) && preg_match('~^@\S*\s+(?:enter |send name)~u', $text)) return $prompts[$callback];
        if (preg_match('~^/setHwid(?:Devices|UserLimit)(?: |$)~', $callback) && preg_match('~^@\S*\s+enter ~u', $text)) {
            return 'Введи максимальное число устройств для загрузки подписки (целое число от 1). Приложение должно поддерживать HWID; уже импортированный VPN-профиль этот счётчик не отключает.';
        }
        if (preg_match('~^/changePort \w+$~D', $callback) && preg_match('~^@\S*\s+number port~u', $text)) {
            return 'Введи внешний номер порта от 1 до 65535. Изменение применится после перезапуска; адрес подключения на устройствах потребуется обновить.';
        }
        return $text;
    }
}
