<?php

trait EternalProfilesUi
{
    protected string $uiProfilePage = 'main';

    public function uiProfilesAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!preg_match('~^/profileui (main|links|files|access|templates) (\d+)$~D', $callback, $m)) return false;
        if (!isset($this->getXray()['inbounds'][0]['settings']['clients'][(int) $m[2]])) {
            $this->uiShow(['Пользователь больше не существует. Открой актуальный список.', [[['text'=>'⬅️ К пользователям', 'callback_data'=>'/xrui users']]]]);
            return true;
        }
        $previous = $this->uiProfilePage;
        if ($this->uiMode() === 'simple') $this->uiProfilePage = $m[1];
        try { $this->userXr((int) $m[2]); }
        finally { $this->uiProfilePage = $previous; }
        return true;
    }

    public function uiProfilesScreen($text, array $buttons): ?array
    {
        $all = array_merge([], ...$buttons);
        $callbacks = array_column($all, 'callback_data');
        $b = fn($label, $callback) => ['text'=>$label, 'callback_data'=>$callback];
        $home = [$b('🏠 На главную', '/menu')];
        foreach ($callbacks as $callback) {
            if (!preg_match('~^/resetXrUser (\d+)$~D', $callback, $m)) continue;
            $index = (int) $m[1];
            $user = $this->getXray()['inbounds'][0]['settings']['clients'][$index] ?? [];
            $name = htmlspecialchars((string) ($user['email'] ?? 'Без имени'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $p = $this->getPacConf();
            $stats = $this->getXrayStats()['users'][$index] ?? [];
            $summary = '<b>👤 ' . $name . "</b>\n\nДоступ: " . (empty($user['off']) ? '🟢 включён' : '⏸ отключён')
                . "\nСрок: " . (!empty($user['time']) ? date('d.m.Y H:i', (int) $user['time']) : 'без ограничения')
                . "\nТрафик: ↓" . $this->uiXrayTraffic($stats, 'download') . '  ↑' . $this->uiXrayTraffic($stats, 'upload');
            $back = [$b('⬅️ К карточке пользователя', "/profileui main $index")];
            if ($this->uiProfilePage === 'main') {
                return [$summary . "\n\nЧто нужно сделать с этим профилем?", [
                    [$b('🔗 Подключение, QR и резерв', "/profileui links $index")],
                    [$b('📥 Файлы для приложений', "/profileui files $index")],
                    [$b('🔐 Доступ, устройства и статистика', "/profileui access $index")],
                    [$b('📄 Шаблоны этого пользователя', "/profileui templates $index")],
                    [$b('⬅️ К списку пользователей', '/xrui users')], $home]];
            }
            $groups = [
                'links' => '~^/(qrXray|funnelMenu|warpMenu|cdnMenu) ~',
                'files' => '~^/dw ~',
                'access' => '~^/(timerXr|switchXr|hwidUser|renameXrUser|delxr|resetXrUser) ~',
                'templates' => '~^/templateUser ~',
            ];
            $rows = [];
            foreach ($all as $button) {
                $cb = $button['callback_data'] ?? '';
                if ($this->uiProfilePage === 'files' && isset($button['web_app'])) {
                    $button['text'] = '👁 Открыть конфигурацию ' . $button['text'];
                    $rows[] = [$button]; continue;
                }
                if (!preg_match($groups[$this->uiProfilePage] ?? '~a^~', $cb)) continue;
                if (str_starts_with($cb, '/switchXr ')) $button['text'] = empty($user['off']) ? '⏸ Отключить доступ…' : '▶️ Включить доступ…';
                elseif (str_starts_with($cb, '/timerXr ')) $button['text'] = '⏳ Изменить срок доступа…';
                elseif (str_starts_with($cb, '/hwidUser ')) $button['text'] = '📱 Устройства и лимит подписки';
                elseif (str_starts_with($cb, '/renameXrUser ')) $button['text'] = '✏️ Переименовать пользователя…';
                elseif (str_starts_with($cb, '/delxr ')) $button['text'] = '🗑 Удалить пользователя…';
                elseif (str_starts_with($cb, '/resetXrUser ')) $button['text'] = '♻️ Сбросить счётчики трафика…';
                elseif (preg_match('~^/dw \d+ (s|si|cl)$~D', $cb, $f)) $button['text'] = '📥 Скачать ' . ['s'=>'Xray / v2ray JSON','si'=>'sing-box JSON','cl'=>'mihomo YAML'][$f[1]];
                elseif (preg_match('~^/templateUser (v2ray|sing|clash)~', $cb, $f)) $button['text'] = '📄 ' . ['v2ray'=>'Xray / v2ray','sing'=>'sing-box','clash'=>'mihomo'][$f[1]] . ': ' . (explode(': ', $button['text'], 2)[1] ?? 'по умолчанию');
                elseif (str_starts_with($cb, '/warpMenu ')) $button['text'] = '☁️ Цепочка через WARP на устройстве';
                elseif (str_starts_with($cb, '/cdnMenu ')) $button['text'] = '🌐 Дополнительный вход CDN';
                elseif (str_starts_with($cb, '/funnelMenu ')) $button['text'] = '🛟 Резервный вход через Funnel';
                elseif (str_starts_with($cb, '/qrXray ')) $button['text'] = '📷 ' . $button['text'];
                $rows[] = [$button];
            }
            if ($this->uiProfilePage === 'links') {
                $pageText = "<b>🔗 Подключение пользователя</b>\nСкопируй ссылку для своего приложения или выбери QR. Резервные маршруты находятся ниже.\n\n" . $text;
                if (mb_strlen(html_entity_decode(strip_tags($pageText))) > 4096) $pageText = $text;
            } elseif ($this->uiProfilePage === 'files') {
                $pageText = $summary . "\n\n<b>📥 Файлы для приложений</b>\nВыбери формат ядра своего клиента. «Открыть» показывает конфигурацию, «Скачать» отправляет файл. Готовые наборы Eternal и инструкции для LxBox, Exclave, Shadowrocket, v2rayN и Karing есть на главной → Подключить устройство.";
            } elseif ($this->uiProfilePage === 'templates') {
                $pageText = $summary . "\n\n<b>📄 Личные шаблоны</b>\nМеняют содержимое файлов этого пользователя при следующем получении настроек. Сами по себе не переключают вход или выход сервера.";
            } else {
                $hwid = !empty($p['hwid_limit_enabled']) && empty($user['hwid_disabled']);
                $pageText = $summary . "\n\n<b>🔐 Управление доступом</b>\nHWID: " . ($hwid ? 'включён' : 'выключен')
                    . "\nЛимит получения подписки: " . max(1, (int) ($user['hwid_limit'] ?? $p['hwid_device_count'] ?? 1))
                    . "\n\nОтключение или удаление отзывает доступ по этому ключу. Таймер задаёт срок действия. Сброс счётчиков перезапускает Xray; остальные пользователи сохраняются.";
            }
            $rows[] = $back; $rows[] = $home;
            return [$pageText, $rows];
        }

        if (in_array('/setHwidDevices', $callbacks, true)) {
            $p = $this->getPacConf();
            return ["<b>📱 Лимит устройств подписки</b>\n\nПроверка HWID: " . (!empty($p['hwid_limit_enabled']) ? 'включена' : 'выключена')
                . "\nЛимит по умолчанию: " . max(1, (int) ($p['hwid_device_count'] ?? 1))
                . "\n\nОграничивает получение подписки совместимыми клиентами. Уже импортированные VPN-ключи не отзывает. Индивидуальные исключения находятся в карточке пользователя.", [
                    [$b(!empty($p['hwid_limit_enabled']) ? '⏸ Выключить проверку HWID…' : '▶️ Включить проверку HWID…', '/toggleHwidLimit')],
                    [$b('🔢 Изменить лимит устройств…', '/setHwidDevices')],
                    [$b('⬅️ К контролю доступа', '/xrui limits')], $home]];
        }

        // Detailed HWID/template screens retain their data and gain task-specific navigation.
        if (preg_grep('~^/(hwidUserToggle|templateAdd|choiceTemplate) ~', $callbacks)) {
            [$text, $rows] = $this->uiLegacyScreen($text, $buttons);
            foreach ($rows as &$row) foreach ($row as &$button) {
                if (preg_match('~^/userXr (\d+)$~D', $button['callback_data'] ?? '', $m)) {
                    $section = preg_grep('~^/hwidUserToggle ~', $callbacks) ? 'access' : 'templates';
                    $button = $b('⬅️ К настройкам пользователя', "/profileui $section {$m[1]}");
                } elseif (($button['callback_data'] ?? '') === '/xrui main') $button = $b('⬅️ К ссылкам и шаблонам', '/xrui templates');
            }
            unset($row, $button);
            return [$text, $rows];
        }

        // Existing special-profile pages already contain tested import steps; preserve payloads.
        $callback = (string) ($this->input['callback'] ?? '');
        if (preg_match('~^/(funnelMenu|cdnMenu|cdnShadowrocketMenu|warpMenu|warpAlternativeMenu|shadowrocketMenu) (\d+)(?:_\d+)?$~D', $callback, $m)) {
            $index = (int) $m[2];
            $text = preg_replace(['~^(<b>)?Eternal — ускоренный WARP~u', '~^(<b>)?Ускоренный профиль~u', '~^(<b>)?Быстрый CDN~u'], ['$1Eternal — цепочка через WARP', '$1Профиль WARP', '$1Вход CDN'], $text);
            if ($m[1] === 'warpMenu') {
                $note = "<b>☁️ WARP перед Eternal</b>\nМаршрут: устройство → WARP → Eternal. Это отдельная схема от «WARP-выход» на сервере.\n\n";
                if (mb_strlen(html_entity_decode(strip_tags($note . $text))) <= 4096) $text = $note . $text;
            }
            $rows = [];
            foreach ($buttons as $row) foreach ($row as $button) {
                $cb = $button['callback_data'] ?? '';
                if ($cb === "/userXr $index") $button = $b('⬅️ К способам подключения', "/profileui links $index");
                elseif (str_starts_with($cb, '/warpMenu ')) $button['text'] = '☁️ К цепочке через WARP';
                elseif (str_starts_with($cb, '/cdnMenu ')) $button['text'] = '🌐 К входу CDN';
                elseif (str_starts_with($cb, '/warpAlternativeMenu ')) $button['text'] = '📱 Другой профиль WARP для LxBox';
                elseif (str_starts_with($cb, '/shadowrocketMenu ')) $button['text'] = str_contains($cb, '_2') ? 'Далее: Eternal ➡️' : '🍎 Shadowrocket · шаг WARP';
                elseif (str_starts_with($cb, '/cdnShadowrocketMenu ')) $button['text'] = str_contains($cb, '_2') ? 'Далее: Eternal ➡️' : '🍎 Shadowrocket · шаг MASQUE';
                $rows[] = [$button];
            }
            $rows[] = $home;
            return [$text, $rows];
        }
        if ($callback === '/eternalExitMenu') {
            $text = "<b>☁️ WARP на выходе Eternal</b>\nВыбери приложение — получишь отдельный набор подключений с серверным выходом через Cloudflare. Старые профили сохранятся.";
            $rows = $buttons;
            foreach ($rows as &$row) foreach ($row as &$button) {
                if (($button['callback_data'] ?? '') === '/menu hy') $button = $b('⬅️ К подключению устройства', '/ui devices');
            }
            unset($row, $button);
            $rows[] = $home;
            return [$text, $rows];
        }
        return null;
    }

    public function uiProfilesPrompt(string $text): ?string
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if ($callback === '/addXrUser') return 'Введи имя нового пользователя VLESS. Для нескольких — имена через запятую. Ключ создаётся автоматически; собственный UUID можно указать как имя:UUID. При добавлении Xray перезапустится.';
        if (preg_match('~^/renameXrUser \d+$~D', $callback)) return 'Введи новое имя пользователя VLESS. Имя появится в списке и новых конфигурациях; ключ доступа останется прежним.';
        if (preg_match('~^/timerXr \d+$~D', $callback)) return 'Введи дату и время окончания доступа, например 2026-12-31 23:59 (в часовом поясе сервера). Ответ 0 убирает срок. Установка срока включит отключённого пользователя.';
        if (preg_match('~^/templateAdd (v2ray|sing|clash)$~D', $callback)) return 'Пришли JSON-файл шаблона. В подписи к файлу укажи его имя. Шаблон станет доступен для выбора; назначить его по умолчанию можно отдельно.';
        if (preg_match('~^/templateCopy (v2ray|sing|clash)$~D', $callback)) return 'Введи имя для копии встроенного шаблона. Копия появится в списке; существующие личные шаблоны сохранятся.';
        return null;
    }
}
