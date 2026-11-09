<?php

trait EternalAdminUi
{
    private function uiAdminButton(string $text, string $callback): array
    {
        return ['text' => $text, 'callback_data' => $callback];
    }

    public function uiConfigScreen(string $page = 'main'): array
    {
        $b = fn($text, $callback) => $this->uiAdminButton($text, $callback);
        $p = $this->getPacConf();
        $e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sections = [
            'network' => ['🌐 Домен, сертификат и порты', 'Домен указывает на сервер, сертификат защищает TLS-подключения. Порты определяют доступные входы. Изменения могут потребовать обновления настроек на устройствах.', ['domain','deldomain','addNipdomain','setSSL','deletessl','selfssl','ports']],
            'backup' => ['💾 Резервные копии', 'Экспорт сохраняет настройки сервера в файл. Импорт восстанавливает их из файла и может заменить действующие настройки. Расписание управляет автоматическими копиями.', ['export','import','backup']],
            'maintenance' => ['🔧 Обновления и перезапуск', 'Обновление меняет код бота. Перезапуск пересоздаёт контейнеры VPN и кратковременно прерывает подключения.', ['autoupdate','menu update','restart']],
            'bot' => ['⚙️ Отображение бота', 'Язык относится к прежним техническим сообщениям. Размер страницы задаёт число пользователей в списках.', ['menu lang','enterPage']],
            'access' => ['👤 Администраторы бота', 'Администратор может получать данные подключения и менять настройки сервера. Добавляй только доверенные Telegram ID.', ['addadmin','deladmin']],
            'diagnostics' => ['🔎 Журналы и защита по IP', 'Журналы помогают найти ошибки. Раздел IP позволяет просматривать подключения, блокировки и исключения.', ['logs','ipMenu']],
        ];
        if (!isset($sections[$page])) {
            $rows = [];
            foreach ($sections as $key => [$label]) $rows[] = [$b($label, '/cfgui ' . $key)];
            $rows[] = [$b('⬅️ К управлению сервером', '/ui admin')];
            $rows[] = [$b('🏠 На главную', '/menu')];
            return ["<b>⚙️ Настройки бота и сервера</b>\n\nВыбери задачу. Внутри каждого раздела указано, что изменится.\n\nДомен: <code>" . $e($p['domain'] ?? 'не задан') . '</code>', $rows];
        }
        [$label, $help, $allowed] = $sections[$page];
        $legacy = $this->configMenu();
        $rows = [];
        $labels = [
            '/domain' => '🌐 Указать домен сервера…', '/deldomain' => '🗑 Удалить привязку домена…',
            '/addNipdomain' => '🌐 Использовать домен nip.io…', '/setSSL letsencrypt' => '🔐 Получить / обновить сертификат…',
            '/deletessl' => '🗑 Удалить TLS-сертификат…', '/selfssl' => '🔐 Создать самоподписанный сертификат…',
            '/ports' => '🔌 Открытые порты и их назначение', '/logs' => '📋 Посмотреть журналы', '/ipMenu' => '🛡 Контроль и блокировки IP',
            '/export' => '📤 Скачать резервную копию', '/import' => '📥 Восстановить из файла…',
            '/backup' => '🗓 Изменить расписание копий…', '/autoupdate' => empty($p['autoupdate']) ? '▶️ Включить автообновление…' : '⏸ Выключить автообновление…',
            '/menu update' => '🌿 Версия и источник обновлений', '/restart' => '🔄 Перезапустить VPN-службы…',
            '/menu lang' => '🌐 Выбрать язык', '/enterPage' => '📃 Изменить размер страницы…', '/addadmin' => '➕ Добавить администратора…',
        ];
        foreach ($legacy['data'] as $row) foreach ($row as $button) {
            $callback = $button['callback_data'] ?? '';
            $key = ltrim($callback, '/');
            if (!in_array($key, $allowed, true) && !in_array(explode(' ', $key)[0], $allowed, true)) continue;
            $button['text'] = $labels[$callback] ?? (str_starts_with($callback, '/deladmin ') ? '🗑 Удалить администратора ' . substr($callback, 10) . '…' : $button['text']);
            $rows[] = [$button];
        }
        $text = '<b>' . $label . '</b>' . "\n\n" . $help;
        if ($page === 'network') $text .= "\n\n" . $legacy['text'];
        if ($page === 'backup') $text .= "\n\nРасписание: <code>" . $e($p['backup'] ?? '' ?: 'не задано') . '</code>';
        if ($page === 'maintenance') $text .= "\n\nАвтообновление: " . (!empty($p['autoupdate']) ? 'включено' : 'выключено');
        if ($page === 'bot') $text .= "\n\nНа странице: " . (int) ($p['limitpage'] ?? 5) . ' пользователей.';
        $rows[] = [$b('⬅️ К настройкам сервера', '/cfgui main')];
        $rows[] = [$b('🏠 На главную', '/menu')];
        return [$text, $rows];
    }

    // Only immediate mutations are reviewed here; value-entry prompts explain their own effect.
    public function uiActionReview(string $callback): ?array
    {
        $p = $this->getPacConf();
        $x = $this->getXray();
        $title = $effect = $current = '';
        $back = '/ui admin';
        if (preg_match('~^/changeTransport (Reality|Websocket|xhttp)$~D', $callback, $m)) {
            $title = 'Переключить основной транспорт на ' . $m[1];
            $current = 'Основной транспорт: ' . ($p['transport'] ?? 'Websocket');
            $effect = 'Основной вход VLESS изменится для всех пользователей. Xray перезапустится, входной прокси перечитает конфигурацию; прежние ссылки этого транспорта могут перестать подключаться. После смены нужно получить и импортировать новые ссылки.';
            $back = '/xrui connections';
        } elseif ($callback === '/grpcReality') {
            $on = !empty($p['grpc_reality']['enabled']);
            $title = ($on ? 'Выключить' : 'Включить') . ' дополнительный вход gRPC Reality';
            $current = 'Дополнительный вход: ' . ($on ? 'включён' : 'выключен');
            $effect = 'Изменится отдельный вход gRPC; основной транспорт останется прежним. Xray перезапустится, входной прокси перечитает конфигурацию. При выключении gRPC его ссылки перестанут работать, в том числе в авто-группах Eternal.';
            $back = '/xrui connections';
        } elseif ($callback === '/resetXrStats' || preg_match('~^/resetXrUser (\d+)$~D', $callback, $m)) {
            $title = $callback === '/resetXrStats' ? 'Обнулить всю статистику VLESS' : 'Обнулить статистику пользователя';
            $effect = 'Счётчики трафика будут сброшены. Ключи пользователей сохранятся. Для сброса перезапускается Xray — активные подключения кратковременно прервутся.';
            $back = $callback === '/resetXrStats' ? '/xrui stats' : '/userXr ' . $m[1];
        } elseif ($callback === '/switchMonthlyStats') {
            $title = (!empty($p['reset_monthly']) ? 'Выключить' : 'Включить') . ' ежемесячный сброс статистики';
            $current = 'Сейчас: ' . (!empty($p['reset_monthly']) ? 'включён' : 'выключен');
            $effect = 'Меняется расписание обнуления счётчиков VLESS. При автоматическом сбросе Xray перезапускается. Сейчас статистика не обнулится.';
            $back = '/xrui stats';
        } elseif (preg_match('~^/toggleHwidLimit(?: xray)?$~D', $callback)) {
            $title = (!empty($p['hwid_limit_enabled']) ? 'Выключить' : 'Включить') . ' лимит устройств подписки';
            $current = 'Лимит: ' . max(1, (int) ($p['hwid_device_count'] ?? 1)) . ' устройств';
            $effect = 'Проверяется HWID при загрузке подписки. Клиенты без поддержки HWID могут перестать получать конфигурацию. Уже импортированный ключ VPN эта проверка сама по себе не отзывает.';
            $back = '/xrui limits';
        } elseif (preg_match('~^/(switchXr|delxr) (\d+)$~D', $callback, $m)) {
            $user = $x['inbounds'][0]['settings']['clients'][(int) $m[2]] ?? null;
            if (!$user) return null;
            $title = $m[1] === 'delxr' ? 'Удалить пользователя VLESS' : (!empty($user['off']) ? 'Включить' : 'Выключить') . ' пользователя VLESS';
            $current = 'Пользователь: ' . ($user['email'] ?? 'без имени');
            $effect = ($m[1] === 'delxr' ? 'Пользователь и его ключ будут удалены; выданные ссылки перестанут работать.' : 'Изменится доступ этого пользователя. При выключении его ссылки перестанут подключаться.') . ' Xray будет перезапущен. Если это владелец набора Eternal, изменение затронет связанные готовые профили.';
            $back = '/userXr ' . $m[2];
        } else {
            $descriptions = [
                '/restart' => ['Перезапустить VPN-службы', 'Контейнеры проекта будут пересозданы. Все VPN-подключения временно прервутся.', '/cfgui maintenance'],
                '/autoupdate' => [(!empty($p['autoupdate']) ? 'Выключить' : 'Включить') . ' автоматическое обновление', 'Меняется разрешение на автоматическое обновление кода бота. Само обновление сейчас не запускается.', '/cfgui maintenance'],
                '/deldomain' => ['Удалить привязку домена', 'Изменятся доменные настройки и конфигурации служб. Ссылки и TLS-подключения с прежним доменом могут перестать работать.', '/cfgui network'],
                '/deletessl' => ['Удалить TLS-сертификат', 'Подключения, использующие этот сертификат, могут перестать работать. Для восстановления потребуется выпустить сертификат заново.', '/cfgui network'],
                '/addNipdomain' => ['Назначить домен nip.io', 'Адрес сервера в настройках изменится на домен nip.io. Настройки домена, сертификата и ссылки потребуется проверить.', '/cfgui network'],
                '/selfFakeDomain' => ['Использовать домен сервера как маскировочный', 'Будет изменена маскировка основного Reality-входа. Старым клиентам может понадобиться новый SNI; Xray и веб-службы перезапустятся.', '/xrui connections'],
                '/adguardreset' => ['Сбросить настройки DNS AdGuard', 'Настройки DNS восстановятся из шаблона репозитория. Пользовательские изменения могут быть потеряны; DNS и связанные службы перезапустятся.', '/menu adguard'],
                '/adgFillAllowedClients 0' => ['Ограничить доступ к DNS', 'Разрешённые клиенты AdGuard будут заполнены сетями VPN и идентификаторами пользователей. Остальные запросы могут блокироваться; DNS перезапустится.', '/menu adguard'],
                '/adgFillAllowedClients 1' => ['Снять ограничения клиентов DNS', 'Список разрешённых клиентов AdGuard очистится, доступ к DNS станет шире. DNS перезапустится.', '/menu adguard'],
                '/v2ray' => ['Изменить WebSocket-обёртку Shadowsocks', 'Shadowsocks и локальный прокси перезапустятся. Прежним клиентам потребуется обновить параметры подключения.', '/menu ss'],
                '/changeOcExpose' => ['Изменить передачу маршрутов OpenConnect', 'Параметр expose-iroutes переключится, служба OpenConnect перезапустится. Меняется передача маршрутов клиентов.', '/menu oc'],
                '/adguardChBr' => ['Изменить доступ к панели DNS из браузера', 'Разрешение входа в панель AdGuard через внешний браузер переключится. Веб-сервер перечитает конфигурацию.', '/menu adguard'],
            ];
            if (isset($descriptions[$callback])) [$title, $effect, $back] = $descriptions[$callback];
            elseif (preg_match('~^/hidePort (wg|wg1|tg|ad|ss|dnstt)$~D', $callback, $m)) {
                $title = 'Изменить внешний доступ к порту ' . $m[1];
                $effect = 'Публикация порта этой службы переключится между открытым и закрытым состоянием. Изменение вступит в силу после перезапуска; закрытый порт перестанет принимать внешние подключения.';
                $back = '/ports';
            } elseif (preg_match('~^/(switchAmnezia|resetAmnezia) (\d+)$~D', $callback, $m)) {
                $title = $m[1] === 'switchAmnezia' ? 'Изменить протокол WireGuard / Amnezia' : 'Обновить ключи маскировки Amnezia';
                $effect = 'Для выбранного WireGuard-сервера изменятся параметры протокола. Служба перезапустится. Старые профили могут перестать работать; устройствам потребуется получить новые конфигурации.';
                $back = '/menu wg ' . $m[2];
            } elseif (preg_match('~^/deloc (\d+)$~D', $callback, $m)) {
                $users = $this->getClientsOc();
                if (!isset($users[(int) $m[1]])) return null;
                $title = 'Удалить пользователя OpenConnect';
                $current = 'Пользователь: ' . $users[(int) $m[1]];
                $effect = 'Выбранная учётная запись OpenConnect будет удалена и потеряет доступ.';
                $back = '/menu oc';
            } elseif (preg_match('~^/deladmin (\d+)$~D', $callback, $m)) {
                $title = 'Удалить администратора ' . $m[1];
                $effect = 'Этот Telegram ID потеряет доступ к управлению ботом и выдаче конфигураций.';
                $back = '/cfgui access';
            } else return null;
        }
        $files = [];
        foreach (['/config/ocserv.conf', '/config/ocserv.passwd', '/config/AdGuardHome.yaml', '/docker/compose', __DIR__ . '/config.php'] as $path) {
            $files[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $fingerprint = hash('sha256', json_encode([$callback, $p, $x, $files], JSON_THROW_ON_ERROR));
        return compact('title', 'effect', 'current', 'back', 'fingerprint');
    }

    public function uiAdminAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (preg_match('~^/cfgui (main|network|backup|maintenance|bot|access|diagnostics)$~D', $callback, $m)) {
            if ($this->uiMode() === 'classic') $this->menu('config');
            else $this->uiShow($this->uiConfigScreen($m[1]));
            return true;
        }
        if (preg_match('~^/(confirmui|cancelui) ([a-f0-9]{24})$~D', $callback, $m)) {
            $pending = $_SESSION['ui_review'][$m[2]] ?? null;
            unset($_SESSION['ui_review'][$m[2]]);
            if (!$pending || $pending['user'] !== (string) ($this->input['from'] ?? '') || time() - $pending['time'] > 300 || $this->uiMode() !== 'simple') {
                $this->uiShow(['Эта кнопка уже не действует. Открой нужную настройку заново.', [[$this->uiAdminButton('🏠 На главную', '/menu')]]]);
                return true;
            }
            if ($m[1] === 'cancelui') {
                $this->uiShow(['Изменение отменено. Настройки сохранены.', [[$this->uiAdminButton('⬅️ Вернуться к настройке', $pending['back'])], [$this->uiAdminButton('🏠 На главную', '/menu')]]]);
                return true;
            }
            $review = $this->uiActionReview($pending['callback']);
            if (!$review || !hash_equals($pending['fingerprint'], $review['fingerprint'])) {
                $this->uiShow(['Настройка успела измениться. Проверь её текущее состояние перед применением.', [[$this->uiAdminButton('⬅️ Проверить настройку', $pending['back'])]]]);
                return true;
            }
            $this->input['callback'] = $pending['callback'];
            return false; // Continue to the unchanged, original action handler exactly once.
        }
        if ($this->uiMode() !== 'simple') return false;
        // Avoid loading network configs for ordinary navigation and message replies.
        if (!preg_match('~^/(changeTransport|grpcReality|resetXrStats|resetXrUser|switchMonthlyStats|toggleHwidLimit|switchXr|delxr|restart|autoupdate|deldomain|deletessl|addNipdomain|selfFakeDomain|adguardreset|adgFillAllowedClients|v2ray|hidePort|deladmin|changeOcExpose|adguardChBr|switchAmnezia|resetAmnezia|deloc)(?: |$)~', $callback)) return false;
        $review = $this->uiActionReview($callback);
        if (!$review) {
            $this->uiShow(['Эта настройка больше недоступна. Открой актуальное меню.', [[$this->uiAdminButton('🏠 На главную', '/menu')]]]);
            return true;
        }
        $token = bin2hex(random_bytes(12));
        $_SESSION['ui_review'] = [$token => ['callback' => $callback, 'fingerprint' => $review['fingerprint'],
            'user' => (string) ($this->input['from'] ?? ''), 'time' => time(), 'back' => $review['back']]];
        $e = fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = '<b>⚠️ ' . $e($review['title']) . "?</b>\n\n";
        if ($review['current'] !== '') $text .= $e($review['current']) . "\n\n";
        $text .= $e($review['effect']);
        $this->uiShow([$text, [[$this->uiAdminButton('✅ Применить изменение', '/confirmui ' . $token)],
            [$this->uiAdminButton('⬅️ Отмена', '/cancelui ' . $token)]]]);
        return true;
    }
}
