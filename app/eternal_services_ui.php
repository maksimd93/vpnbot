<?php

// Read-only navigation and wording for protocol and maintenance screens.
trait EternalServicesUi
{
    private string $uiServicePage = 'main';

    public function uiServicesAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!preg_match('~^/serviceui (oc|dns) (main|connection|users|settings|access|upstreams)$~D', $callback, $m)) return false;
        $previous = $this->uiServicePage;
        $this->uiServicePage = $m[2];
        try { $this->menu($m[1] === 'oc' ? 'oc' : 'adguard'); }
        finally { $this->uiServicePage = $previous; }
        return true;
    }

    public function uiServicesScreen($text, array $buttons): ?array
    {
        if (!is_string($text)) return null;
        $originalText = $text;
        $flat = [];
        foreach ($buttons as $row) foreach ($row as $button) $flat[] = $button;
        $callbacks = array_values(array_filter(array_column($flat, 'callback_data'), 'is_string'));
        $has = fn($pattern) => (bool) preg_grep($pattern, $callbacks);
        $current = (string) ($this->input['callback'] ?? '');
        $section = match (true) {
            $has('~^/generateSecret$~D') => 'telegram',
            $has('~^/showdnstt$~D') => 'dnstt',
            $has('~^/offWarp$~D') => 'warp',
            $has('~^/getMirror$~D') => 'mirror',
            $has('~^/changeBranch ~') || $current === '/branches' => 'branches',
            $has('~^/applyupdatebot$~D') => 'update',
            $has('~^/hidePort ~') => 'ports',
            $has('~^/lang ~') || $text === 'Language' => 'language',
            $has('~^/reset$~D') => 'reset',
            str_starts_with($text, 'Server config:') => 'config',
            $has('~^/changeHysteriaPass$~D') => 'hysteria',
            $has('~^/changeNaiveUser$~D') => 'naive',
            $has('~^/changeOcExpose$~D') => 'oc',
            $has('~^/sspswd$~D') => 'ss',
            $has('~^/adguardreset$~D') => 'dns',
            default => '',
        };
        if ($section === '') return null;
        $p = $this->getPacConf();
        $b = fn($label, $callback) => ['text' => $label, 'callback_data' => $callback];
        $home = [$b('🏠 На главную', '/menu')];
        $protocols = [$b('⬅️ К протоколам', '/ui protocols admin')];
        $escape = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Large service screens have their own task hierarchy, not a wall of toggles.
        if ($section === 'oc' || $section === 'dns') {
            $groups = $section === 'oc' ? [
                'connection' => ['🔗 Данные подключения', 'Адрес, пароль и имя пользователя нужны клиенту OpenConnect. Пароль общий; список учётных записей находится в разделе «Пользователи».', []],
                'users' => ['👤 Пользователи OpenConnect', 'Добавляй отдельные имена пользователей. Удаление учётной записи лишает её доступа; остальные пользователи сохраняются.', ['addOcUser', 'deloc']],
                'settings' => ['⚙️ Настройки OpenConnect', 'Адрес, секрет и общий пароль меняют параметры подключения. DNS и подсети задают сетевые настройки; после изменения служба может перезапуститься.', ['changeOcDomain','changeCamouflage','changeOcPass','changeOcDns','subnet','changeOcExpose']],
            ] : [
                'connection' => ['🔗 Панель и адреса DNS', 'В панели AdGuard доступны запросы и фильтры. Адреса DNS ниже используются в настройках устройств. Пароль панели не является паролем VPN.', []],
                'access' => ['🔐 Доступ к DNS и панели', 'Здесь отдельно управляются вход в веб-панель и список разрешённых DNS-клиентов. Снятие ограничений расширит доступ к DNS.', ['adguardChBr','adguardpsswd','setAdguardKey','adgFillAllowedClients']],
                'upstreams' => ['🌐 Вышестоящие DNS', 'AdGuard отправляет запросы этим резолверам. Добавление или удаление адреса перезапускает DNS; проверка адреса только выполняет тестовый запрос.', ['addupstream','delupstream','checkdns','menu adguard']],
                'settings' => ['♻️ Восстановление DNS', 'Сброс заменяет настройки AdGuard шаблоном из репозитория. Пользовательские изменения могут быть потеряны, DNS перезапустится.', ['adguardreset']],
            ];
            $page = isset($groups[$this->uiServicePage]) ? $this->uiServicePage : 'main';
            if ($page === 'main') {
                $title = $section === 'oc' ? '🔐 OpenConnect' : '🛡 DNS · AdGuard Home';
                $intro = $section === 'oc' ? 'Выбери задачу: получить данные подключения, управлять пользователями или изменить параметры сервера.'
                    : 'DNS преобразует имена сайтов в адреса и фильтрует запросы. Выбери задачу; просмотр разделов не меняет параметры DNS.';
                $rows = [];
                foreach ($groups as $key => [$label]) $rows[] = [$b($label, "/serviceui $section $key")];
                $rows[] = $section === 'oc' ? $protocols : [$b('⬅️ К управлению сервером', '/ui admin')];
                $rows[] = $home;
                return ['<b>' . $title . "</b>\n\n" . $intro, $rows];
            }
            [$title, $intro, $allowed] = $groups[$page];
            $visible = [];
            foreach ($flat as $button) {
                $cb = $button['callback_data'] ?? '';
                if ($page === 'connection') {
                    if ($section === 'dns' && isset($button['web_app'])) $visible[] = $button;
                } else {
                    $key = ltrim($cb, '/');
                    if (in_array($key, $allowed, true) || in_array(explode(' ', $key)[0], $allowed, true)) $visible[] = $button;
                }
            }
            $text = $page === 'connection' ? "<b>$title</b>\n\n$intro\n\n" . preg_replace('~^Menu -> [^\n]+\n?~', '', $text)
                : "<b>$title</b>\n\n$intro";
            $buttons = array_map(fn($button) => [$button], $visible);
            $back = [$b($section === 'oc' ? '⬅️ К разделам OpenConnect' : '⬅️ К разделам DNS', "/serviceui $section main")];
        } else {
            $copy = [
                'telegram' => ['✈️ Прокси для Telegram', 'Эта ссылка подключает прокси только в Telegram. Новый секрет или домен маскировки изменит параметры подключения; после сохранения обнови ссылку у пользователей.'],
                'dnstt' => ['🌐 Туннель через DNS · DNSTT', 'Для работы нужны делегированный поддомен и указанные DNS-записи. Логин и пароль используются для входа через туннель. Переключатель видимости только показывает или скрывает раздел в меню.'],
                'warp' => ['☁️ Старый WARP-прокси', 'Это прежняя служба warp-cli. Она отделена от «Eternal · WARP-выход», который работает в другом контейнере. Переключение здесь не включает и не выключает новый WARP-выход.'],
                'mirror' => ['🪞 Промежуточный сервер', 'Скрипт создаёт пересылку соединений через другой VPS. Само скачивание ничего не запускает. Для обычного подключения к Eternal этот способ не требуется.'],
                'branches' => ['🌿 Источник обновлений', 'Выбор ветки задаёт источник следующего обновления. Код не заменяется до запуска обновления. Проверяй ветку своего форка перед применением.'],
                'update' => ['🔧 Версия и обновление бота', 'Сначала выбери источник, затем запускай обновление. Обновление заменяет код и может прервать работу служб; сейчас показаны установленные и отслеживаемые версии.'],
                'ports' => ['🔌 Внешние порты служб', 'Кнопка «Закрыть» убирает внешний вход, «Открыть» возвращает его. Изменения Docker применяются после перезапуска. Смена номера порта требует обновить адрес подключения на устройствах.'],
                'language' => ['🌐 Язык сообщений', 'Выбери язык прежних технических сообщений бота. Новый интерфейс Eternal остаётся русским. Текущий язык: ' . $escape($this->language ?? $p['language'] ?? 'не задан') . '.'],
                'reset' => ['⚠️ Сброс WireGuard', 'Сервер WireGuard будет создан заново, список его клиентов очистится. Прежние конфигурации перестанут работать. Это действие не является возвратом старого интерфейса.'],
                'config' => ['📄 Конфигурация сервера WireGuard', 'Ниже полный конфигурационный файл, включая данные доступа. Просмотр ничего не меняет. Не отправляй этот файл посторонним.'],
                'hysteria' => ['🚀 Hysteria · подключение', 'Данные ниже предназначены для импорта в клиент Hysteria. Общий пароль используется всеми профилями этого протокола. После смены пароля обнови настройки на устройствах.'],
                'naive' => ['🌐 NaiveProxy · подключение', 'Ссылка содержит адрес, логин и пароль. Изменение любого из них потребует обновить параметры клиента.'],
                'ss' => ['🔐 Shadowsocks · подключение', 'Ссылка и QR-код содержат текущие параметры. WebSocket-плагин меняет способ подключения; после его переключения или смены пароля обнови настройки клиента.'],
            ];
            [$title, $intro] = $copy[$section];
            if ($section === 'language' || $section === 'branches' || $section === 'reset') $text = "<b>$title</b>\n\n$intro";
            else $text = "<b>$title</b>\n\n$intro\n\n" . preg_replace('~^Menu -> [^\n]+\n?~', '', $text);
            $back = match ($section) {
                'language' => [$b('⬅️ К отображению бота', '/cfgui bot')],
                'ports' => [$b('⬅️ К домену и портам', '/cfgui network')],
                'update' => [$b('⬅️ К обслуживанию', '/cfgui maintenance')],
                'branches' => [$b('⬅️ К версии и обновлению', '/menu update')],
                'warp', 'mirror' => [$b('⬅️ К управлению сервером', '/ui admin')],
                'reset', 'config' => [$b('⬅️ К управлению сервером', '/ui admin')],
                default => $protocols,
            };
        }

        $fixed = [
            '/generateSecret' => '🔑 Создать новый секрет Telegram…', '/setSecret' => '✏️ Ввести секрет или остановить прокси…',
            '/changeTGDomain' => '🌐 Изменить домен маскировки…', '/qrMtproto' => '📷 Показать QR-код Telegram',
            '/dnsttDownload' => '📥 Скачать публичный ключ', '/dnsttDomain' => '🌐 Изменить поддомен DNSTT…', '/dnsttPassword' => '🔑 Изменить пароль DNSTT…',
            '/warpPlus' => '🔑 Указать лицензию WARP+…', '/getMirror' => '📥 Скачать скрипт для другого VPS',
            '/applyupdatebot' => '⬆️ Применить обновление бота…', '/branches' => '🌿 Изменить ветку обновлений', '/restart' => '🔄 Перезапустить службы…',
            '/reset' => '🗑 Подтвердить сброс WireGuard',
            '/changeHysteriaPass' => '🔑 Изменить общий пароль Hysteria…', '/eternalAutoFile' => '📥 LxBox · набор Eternal',
            '/eternalExclave' => '📥 Exclave · набор Eternal', '/eternalShadowrocket' => '📥 Shadowrocket · набор Eternal',
            '/eternalExitMenu' => '☁️ Набор с выходом через WARP', '/xray' => '🛡 Пользователи и подключения VLESS',
            '/changeNaiveSubdomain' => '🌐 Изменить адрес NaiveProxy…', '/changeNaiveUser' => '👤 Изменить логин…', '/changeNaivePass' => '🔑 Изменить пароль…',
            '/changeOcDomain' => '🌐 Изменить поддомен OpenConnect…', '/changeCamouflage' => '🔐 Изменить секрет в URL…', '/changeOcPass' => '🔑 Изменить общий пароль…',
            '/addOcUser' => '➕ Добавить пользователя…', '/sspswd' => '🔑 Изменить пароль Shadowsocks…', '/qrSS' => '📷 Показать QR-код',
            '/adguardpsswd' => '🔑 Изменить пароль панели…', '/setAdguardKey' => '🔐 Изменить ClientID DNS…',
            '/adgFillAllowedClients 0' => '🔒 Разрешить DNS только клиентам VPN…', '/adgFillAllowedClients 1' => '🔓 Снять ограничения клиентов DNS…',
            '/checkdns' => '🔎 Проверить адрес DNS…', '/adguardreset' => '♻️ Сбросить настройки AdGuard…', '/addupstream' => '➕ Добавить вышестоящий DNS…',
        ];
        $rows = [];
        foreach ($buttons as $row) foreach ($row as $button) {
            $cb = $button['callback_data'] ?? '';
            $old = (string) ($button['text'] ?? '');
            if (in_array($cb, ['/menu', '/menu config', '/menu update'], true)) continue;
            if ($cb === '/branches') $button['text'] = '🌿 Ветка: ' . str_replace('=>', '→', $old) . ' · изменить';
            elseif (isset($fixed[$cb])) $button['text'] = $fixed[$cb];
            elseif ($cb === '/showdnstt') $button['text'] = !empty($p['showdnstt']) ? '🙈 Скрыть DNSTT из списка протоколов' : '👁 Показать DNSTT в списке протоколов';
            elseif ($cb === '/offWarp') $button['text'] = !empty($p['warpoff']) ? '▶️ Включить старый WARP-прокси…' : '⏸ Выключить старый WARP-прокси…';
            elseif ($cb === '/v2ray') $button['text'] = str_contains($old, 'ON') ? '⏸ Выключить WebSocket-плагин…' : '▶️ Включить WebSocket-плагин…';
            elseif ($cb === '/changeOcDns') $button['text'] = '✏️ Изменить DNS · ' . preg_replace('~^[^:]*:\s*~u', '', $old);
            elseif ($cb === '/changeOcExpose') $button['text'] = str_ends_with(trim($old), $this->i18n('on')) ? '⏸ Не передавать маршруты клиентов…' : '▶️ Передавать маршруты клиентов…';
            elseif ($cb === '/adguardChBr') $button['text'] = !empty($p['adgbrowser']) ? '🔒 Запретить панель во внешнем браузере…' : '🌐 Разрешить панель во внешнем браузере…';
            elseif (preg_match('~^/hidePort \w+$~D', $cb)) $button['text'] = (str_starts_with(trim($old), $this->i18n('on')) ? '🔒 Закрыть: ' : '🔓 Открыть: ') . preg_replace('~^\S+\s+~u', '', $old);
            elseif ($cb === '/changePort hy') $button['text'] = '✏️ Изменить внешний порт Hysteria…';
            elseif (preg_match('~^/subnet ~', $cb)) $button['text'] = '📍 Подсети OpenConnect';
            elseif (preg_match('~^/deloc \d+$~D', $cb)) $button['text'] = '🗑 Удалить: ' . preg_replace('~^\S+\s+~u', '', $old);
            elseif (preg_match('~^/delupstream \d+$~D', $cb)) $button['text'] = '🗑 Удалить этот DNS';
            elseif (preg_match('~^/changeBranch \d+$~D', $cb)) $button['text'] = '🌿 Выбрать: ' . trim($old);
            elseif (preg_match('~^/lang (\S+)$~D', $cb, $m)) $button['text'] = '🌐 ' . (['ru'=>'Русский','en'=>'English','uk'=>'Українська','de'=>'Deutsch','es'=>'Español','zh'=>'中文'][$m[1]] ?? $m[1]);
            if (isset($button['web_app']) && $section === 'dns') $button['text'] = '🌐 Открыть панель AdGuard';
            elseif (isset($button['web_app']) && $section === 'update') $button['text'] = '📋 История изменений';
            $rows[] = [$button];
        }
        if ($section === 'hysteria') $rows[] = [$b('📱 Все приложения и инструкции', '/ui devices')];
        $rows[] = $back;
        $rows[] = $home;
        if (mb_strlen(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')) > 4096) $text = $originalText;
        return [$text, $rows];
    }

    public function uiServicesPrompt(string $text): ?string
    {
        $cb = (string) ($this->input['callback'] ?? '');
        $prompts = [
            '/addOcUser' => 'Введи одно имя нового пользователя OpenConnect, без пробелов. Он будет подключаться с общим паролем OpenConnect, указанным в данных подключения.',
            '/setSecret' => 'Введи секрет MTProto из 32 шестнадцатеричных символов (0–9 и a–f). Значение 0 остановит Telegram-прокси. После смены секрета служба перезапустится, прежнюю ссылку потребуется заменить.',
            '/changeTGDomain' => 'Введи домен маскировки Telegram-прокси, например example.org, без https:// и пути. Прокси перезапустится; выдай пользователям новую ссылку.',
            '/dnsttDomain' => 'Введи поддомен туннеля DNSTT без https://. Его NS-запись должна вести на DNS-узел сервера. После сохранения DNSTT перезапустится.',
            '/dnsttPassword' => 'Введи новый пароль учётной записи vpnbot для DNSTT. После сохранения туннель перезапустится; обнови пароль в клиентах.',
            '/warpPlus' => 'Введи лицензионный ключ WARP+ для старого WARP-прокси. Это не ключ нового «Eternal · WARP-выход» и не настройка мобильного приложения.',
            '/backup' => 'Введи начало и период резервного копирования через /, например now / 12 hours или 2026-12-01 03:00 / 1 day. Будет сохранено расписание; оно не запускает восстановление настроек.',
            '/import' => 'Прикрепи JSON-файл резервной копии, ранее выданный этим ботом. Восстановление заменяет настройки и данные доступа служб Eternal из копии, включая оба WireGuard-инстанса. Службы перезапустятся, подключения могут прерваться. Файлы подключения из LxBox сюда не подходят.',
            '/domain' => 'Введи основной домен сервера без https:// и пути, например vpn.example.org. Он должен уже указывать на IP Eternal. Смена адреса затронет доменные подключения и ссылки.',
            '/selfssl' => 'Прикрепи PEM-файл со своим приватным ключом и цепочкой TLS-сертификата. Порядок: key.pem, ca.pem, cert.pem — например результат команды cat key.pem ca.pem cert.pem. Сертификат должен подходить домену сервера. После установки TLS-службы перечитают настройки.',
            '/adguardpsswd' => 'Введи новый пароль входа в панель AdGuard Home. Это пароль панели DNS, а не VPN. После сохранения AdGuard перезапустится.',
            '/setAdguardKey' => 'Введи ClientID для адресов DNS-over-HTTPS / DNS-over-TLS. Его используют для различения клиентов AdGuard; после изменения проверь адреса DNS на устройствах.',
            '/checkdns' => "Введи проверяемое имя и DNS-адрес через пробел. Примеры:\n<code>example.org 1.1.1.1</code>\n<code>example.org tls://dns.google</code>\n<code>example.org https://dns.google/dns-query</code>\nБудет выполнен тестовый запрос; DNS сервера не изменится.",
            '/addupstream' => 'Введи адрес DNS-резолвера для AdGuard, например 1.1.1.1, tls://dns.google или https://dns.google/dns-query. Он добавится в список вышестоящих DNS, после чего AdGuard перезапустится.',
            '/addadmin' => 'Введи числовой Telegram ID нового администратора. Он получит управление сервером и доступ к конфигурациям VPN. Узнать свой ID можно командой /id.',
            '/addSubdomain' => 'Введи дополнительные поддомены через запятую. Они сохранятся в настройках домена; DNS-записи у провайдера эта команда сама не создаёт.',
            '/enterPage' => 'Введи число записей на странице, например 5 или 10. Это меняет длину списков бота, а не число разрешённых VPN-устройств.',
            '/addOverrideHtml' => 'Прикрепи HTML-файл для собственной страницы веб-интерфейса бота. Он заменит пользовательский override.html; конфигурации VPN останутся прежними.',
        ];
        if (isset($prompts[$cb]) && preg_match('~^@\S*\s+(?:enter|send|attach)\b~u', $text)) return $prompts[$cb];
        return null;
    }
}
