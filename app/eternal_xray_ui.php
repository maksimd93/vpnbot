<?php

// Read-only Xray administration screens. Mutations retain the existing handlers.
trait EternalXrayUi
{
    private function uiXrayEscape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function uiXrayTraffic(array $stats, string $direction): string
    {
        $bytes = max(0, (float) ($stats['global'][$direction] ?? 0) + (float) ($stats['session'][$direction] ?? 0));
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ', 'ПБ'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) { $bytes /= 1024; $unit++; }
        return round($bytes, 2) . ' ' . $units[$unit];
    }

    public function uiXrayScreen(string $page = 'main', int $offset = 0, string $filter = 'all'): array
    {
        $p = $this->getPacConf();
        $xray = $this->getXray();
        $users = $xray['inbounds'][0]['settings']['clients'] ?? [];
        $stats = $this->getXrayStats();
        $grpc = is_array($p['grpc_reality'] ?? null) ? $p['grpc_reality'] : [];
        $b = fn(string $text, string $callback) => ['text' => $text, 'callback_data' => $callback];
        $back = [$b('⬅️ К разделам VLESS', '/xrui main')];
        $home = [$b('🏠 На главную', '/menu')];
        $transport = ($p['transport'] ?? '') ?: 'Websocket';
        $transportNames = ['xhttp' => 'XHTTP', 'Websocket' => 'WebSocket', 'Reality' => 'TCP / REALITY'];
        $transportName = $transportNames[$transport] ?? $transport;
        $on = count(array_filter($users, fn($u) => empty($u['off'])));
        $off = count($users) - $on;

        switch ($page) {
            case 'users':
                $filter = in_array($filter, ['all', 'on', 'off'], true) ? $filter : 'all';
                $selected = array_filter($users, fn($u) => $filter === 'all'
                    || ($filter === 'on' ? empty($u['off']) : !empty($u['off'])));
                uasort($selected, fn($a, $b) => (($a['time'] ?? 0) ?: PHP_INT_MAX) <=> (($b['time'] ?? 0) ?: PHP_INT_MAX));
                $perPage = max(1, min(20, (int) ($this->limit ?: 5)));
                $pages = max(1, (int) ceil(count($selected) / $perPage));
                $offset = min(max(0, $offset), $pages - 1);
                $text = "<b>👤 VLESS · Пользователи и ссылки</b>\n\n"
                    . "Активны: $on · Отключены: $off\n"
                    . 'Открой пользователя, чтобы получить его ссылку, QR-код или изменить доступ.';
                $rows = [];
                foreach (['all' => ['Все', count($users)], 'on' => ['Активные', $on], 'off' => ['Отключённые', $off]] as $key => [$label, $count]) {
                    $rows[] = [$b(($filter === $key ? '✅ ' : '👥 ') . "$label ($count)", "/xrui users $key 0")];
                }
                foreach (array_slice($selected, $offset * $perPage, $perPage, true) as $index => $user) {
                    $name = mb_strimwidth((string) (($user['email'] ?? '') ?: 'Без имени'), 0, 50, '…', 'UTF-8');
                    $userStats = $stats['users'][$index] ?? [];
                    $usage = '↓' . $this->uiXrayTraffic($userStats, 'download') . ' ↑' . $this->uiXrayTraffic($userStats, 'upload');
                    $rows[] = [$b((empty($user['off']) ? '🟢 ' : '⏸ ') . $name . " · $usage", "/userXr $index")];
                }
                if (!$selected) $text .= "\n\nВ этом списке пока нет пользователей.";
                if ($pages > 1) {
                    $rows[] = [$b('⬅️ Ранее', '/xrui users ' . $filter . ' ' . max(0, $offset - 1)),
                        $b(($offset + 1) . " / $pages", "/xrui users $filter $offset"),
                        $b('Далее ➡️', '/xrui users ' . $filter . ' ' . min($pages - 1, $offset + 1))];
                }
                $rows[] = [$b('➕ Добавить пользователя', '/addXrUser')];
                $rows[] = $back; $rows[] = $home;
                return [$text, $rows];

            case 'connections':
                $text = "<b>🔌 VLESS · Подключения к серверу</b>\n\n"
                    . '<b>Основной транспорт: ' . $this->uiXrayEscape($transportName) . "</b>\n"
                    . "Это способ подключения клиентов к основному входу VLESS. При смене транспорта потребуется обновить настройки клиентов; соединения Xray могут прерваться.\n\n"
                    . '<b>Дополнительный gRPC / REALITY: ' . (!empty($grpc['enabled']) ? 'включён' : 'выключен') . "</b>\n"
                    . "Работает параллельно с основным транспортом. Выключение уберёт этот вход из работающих подключений.\n";
                if (!empty($grpc['enabled'])) $text .= 'Домен gRPC (SNI): <code>' . $this->uiXrayEscape($grpc['domain'] ?? '') . "</code>\n";
                $text .= "\nSNI — имя сайта для TLS/REALITY. Оно должно совпадать в настройках сервера и клиента.";
                $rows = [];
                foreach ($transportNames as $value => $label) {
                    $rows[] = [$b($transport === $value ? "✅ $label · выбран" : "🔀 Переключить на $label",
                        $transport === $value ? '/xrui connections' : '/changeTransport ' . $value)];
                }
                $rows[] = [$b(!empty($grpc['enabled']) ? '⏸ Выключить gRPC / REALITY' : '▶️ Включить gRPC / REALITY', '/grpcReality')];
                if (!empty($grpc['enabled'])) $rows[] = [$b('✏️ Изменить домен gRPC (SNI)', '/grpcRealityDomain')];
                if ($transport === 'Reality') {
                    $domain = $xray['inbounds'][0]['streamSettings']['realitySettings']['serverNames'][0] ?? $p['reality']['domain'] ?? '';
                    $text .= "\nДомен основного REALITY: <code>" . $this->uiXrayEscape($domain) . '</code>';
                    $rows[] = [$b('✏️ Изменить домен основного REALITY', '/changeFakeDomain')];
                    $rows[] = [$b('🏠 Использовать свой домен для REALITY', '/selfFakeDomain')];
                }
                $rows[] = $back; $rows[] = $home;
                return [$text, $rows];

            case 'limits':
                $seconds = max(0, (int) ($p['ip_limit'] ?? 0));
                $ipCount = max(1, (int) ($p['ip_count'] ?? 1));
                $hwid = !empty($p['hwid_limit_enabled']);
                $devices = max(1, (int) ($p['hwid_device_count'] ?? 1));
                $text = "<b>🔐 VLESS · Устройства и контроль IP</b>\n\n"
                    . '<b>Уведомления о нескольких IP: ' . ($seconds > 0 ? "включены</b>\nОкно: $seconds с · порог: $ipCount IPv4."
                        : 'выключены</b>')
                    . "\nБот сообщает администратору о превышении числа замеченных IPv4 за это время. Автоматического отключения пользователя эта настройка не делает.\n\n"
                    . '<b>Ограничение устройств по HWID: ' . ($hwid ? 'включено' : 'выключено') . "</b>\n"
                    . "По умолчанию: $devices устройств на пользователя. Индивидуальное число меняется в карточке пользователя.\n\n"
                    . "HWID проверяется при получении конфигурации: приложение должно передавать идентификатор устройства. Уже скачанный ключ этим не отключается; открытие ссылки браузером проходит без проверки HWID.";
                return [$text, [[$b('✏️ Настроить уведомления по IP', '/setIpLimit')],
                    [$b($hwid ? '⏸ Выключить ограничение HWID' : '▶️ Включить ограничение HWID', '/toggleHwidLimit xray')],
                    [$b('🔢 Изменить число устройств по умолчанию', '/setHwidDevices xray')],
                    [$b('👤 Устройства отдельного пользователя', '/xrui users all 0')], $back, $home]];

            case 'stats':
                $monthly = !empty($p['reset_monthly']);
                $text = "<b>📊 VLESS · Статистика трафика</b>\n\n"
                    . 'Скачано: <b>' . $this->uiXrayTraffic($stats, 'download') . "</b>\n"
                    . 'Отправлено: <b>' . $this->uiXrayTraffic($stats, 'upload') . "</b>\n\n"
                    . 'Ежемесячный сброс: <b>' . ($monthly ? 'включён' : 'выключен') . "</b>\n"
                    . "Счётчики показывают накопленный трафик Xray. Статистика каждого пользователя доступна в его карточке.\n\n"
                    . 'Сброс обнуляет статистику и перезапускает Xray: текущие соединения могут прерваться. Ключи пользователей сохраняются.';
                return [$text, [[$b('🧹 Сбросить все счётчики Xray', '/resetXrStats')],
                    [$b($monthly ? '⏸ Выключить ежемесячный сброс' : '▶️ Включить ежемесячный сброс', '/switchMonthlyStats')],
                    [$b('👤 Трафик по пользователям', '/xrui users all 0')], $back, $home]];

            case 'templates':
                $outbound = ($p['outbound'] ?? '') ?: 'proxy';
                $domain = ($p['linkdomain'] ?? '') ?: 'основной домен сервера';
                $text = "<b>📄 VLESS · Ссылки и шаблоны</b>\n\n"
                    . "Шаблоны определяют содержимое выдаваемых конфигураций для v2ray, sing-box и mihomo. Выбери формат своего клиента.\n\n"
                    . 'Имя прокси в конфигурациях: <code>' . $this->uiXrayEscape($outbound) . "</code>\n"
                    . "Это внутреннее имя (tag) для правил клиентского файла. Оно не меняет выходной IP сервера.\n\n"
                    . 'Адрес для ссылок: <code>' . $this->uiXrayEscape($domain) . "</code>\n"
                    . 'Дополнительный домен подставляется в выдаваемые ссылки. CDN или туннель эта настройка не создаёт.';
                return [$text, [[$b('📄 Шаблоны v2ray / Xray', '/templates v2ray')],
                    [$b('📄 Шаблоны sing-box', '/templates sing')], [$b('📄 Шаблоны mihomo', '/templates clash')],
                    [$b('✏️ Изменить имя прокси в конфигурациях', '/mainOutbound')],
                    [$b('🌐 Изменить адрес для ссылок', '/addLinkDomain')],
                    [$b('🧭 Правила клиентской маршрутизации', '/routes')], $back, $home]];

            default:
                $text = "<b>🛡 VLESS / Xray · Управление</b>\n\n"
                    . "Пользователи: $on активных · $off отключённых\n"
                    . 'Основной транспорт: <b>' . $this->uiXrayEscape($transportName) . "</b>\n"
                    . 'Дополнительный gRPC / REALITY: <b>' . (!empty($grpc['enabled']) ? 'включён' : 'выключен') . "</b>\n\n"
                    . "Нужен VPN на устройстве — открой «Пользователи и ссылки». Настройки сервера разделены ниже по назначению.";
                return [$text, [[$b('👤 Пользователи и ссылки', '/xrui users all 0')],
                    [$b('🔌 Подключения к серверу', '/xrui connections')],
                    [$b('🔐 Устройства и контроль IP', '/xrui limits')],
                    [$b('📊 Статистика трафика', '/xrui stats')],
                    [$b('📄 Ссылки, шаблоны и маршрутизация', '/xrui templates')],
                    [$b('⬅️ К управлению сервером', '/ui admin')], $home]];
        }
    }

    public function uiXrayAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if ($this->uiMode() === 'classic') {
            if (str_starts_with($callback, '/xrui ')) { $this->xray(); return true; }
            return false;
        }
        if (preg_match('~^/xrui (main|connections|limits|stats|templates)$~D', $callback, $m)) {
            $this->uiShow($this->uiXrayScreen($m[1]));
        } elseif (preg_match('~^/xrui users(?: (all|on|off)(?: (\d+))?)?$~D', $callback, $m)) {
            $this->uiShow($this->uiXrayScreen('users', (int) ($m[2] ?? 0), $m[1] ?? 'all'));
        } elseif (preg_match('~^/listXr ([01])$~D', $callback, $m)) {
            // Legacy filters remain read-only in the new interface.
            $this->uiShow($this->uiXrayScreen('users', 0, $m[1] === '1' ? 'off' : 'on'));
        } elseif (preg_match('~^/xray (\d+)$~D', $callback, $m)) {
            $this->uiShow($this->uiXrayScreen('users', (int) $m[1], empty($this->getPacConf()['xtlslist']) ? 'on' : 'off'));
        } elseif (str_starts_with($callback, '/xrui ')) {
            $this->uiShow($this->uiXrayScreen());
        } else { return false; }
        return true;
    }
}
