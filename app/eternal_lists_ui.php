<?php

// Presentation for routing lists, PAC files, IP protection and logs.
trait EternalListsUi
{
    private array $uiListsView = [];

    private function uiListsButton(string $text, string $callback): array
    {
        return ['text' => $text, 'callback_data' => $callback];
    }

    private function uiListsFinish(string $text, array $rows, string $back, string $backLabel): array
    {
        $rows[] = [$this->uiListsButton('⬅️ ' . $backLabel, $back)];
        $rows[] = [$this->uiListsButton('🏠 На главную', '/menu')];
        return [$text, $rows];
    }

    private function uiListsPrefix(string $header, string $source): string
    {
        // Do not truncate a credential, URL or diagnostic line to make room for explanatory copy.
        return mb_strlen($header . $source, 'UTF-8') <= 4096 ? $header . $source : $source;
    }

    private function uiListsTypes(): array
    {
        return [
            'includelist' => ['Домены через прокси / PAC', 'xtlsproxy'],
            'blocklist' => ['Блокировка доменов', 'xtlsblock'],
            'warplist' => ['Домены через WARP-прокси', 'xtlswarp'],
            'packagelist' => ['Android-приложения', 'xtlsapp'],
            'processlist' => ['Процессы на компьютере', 'xtlsprocess'],
            'subnetlist' => ['IP-подсети', 'xtlssubnet'],
            'rulessetlist' => ['Наборы правил по ссылке', 'xtlsrulesset'],
        ];
    }

    public function uiListsAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!str_starts_with($callback, '/listsui ')) return false;
        if ($this->uiMode() !== 'simple') { $this->menu(); return true; }
        $previous = $this->uiListsView;
        try {
            if (preg_match('~^/listsui pac (main|links|entries|sources)(?: (\d+))?$~D', $callback, $m)) {
                $this->uiListsView = ['section' => 'pac', 'page' => $m[1]];
                $this->pacMenu((int) ($m[2] ?? 0));
            } elseif (preg_match('~^/listsui security (main|automation)$~D', $callback, $m)) {
                $this->uiListsView = ['section' => 'security', 'page' => $m[1]];
                $this->ipMenu();
            } elseif (preg_match('~^/listsui logs (files|maintenance)(?: (\d+))?$~D', $callback, $m)) {
                $this->uiListsView = ['section' => 'logs', 'page' => $m[1], 'offset' => (int) ($m[2] ?? 0)];
                $this->logs();
            } elseif (preg_match('~^/listsui (list|tools) (includelist|blocklist|warplist|packagelist|processlist|subnetlist|rulessetlist) (pac|routes) (\d+)$~D', $callback, $m)) {
                $this->uiListsView = ['section' => $m[3] === 'pac' ? 'pac' : 'list', 'page' => $m[1] === 'tools' ? 'tools' : 'entries'];
                $method = $m[3] === 'pac' && $m[2] === 'includelist' ? 'pacMenu' : $this->uiListsTypes()[$m[2]][1];
                $this->{$method}((int) $m[4]);
            } else {
                $this->uiShow($this->uiListsFinish('Этот раздел больше недоступен. Открой нужную задачу из меню.', [], '/ui admin', 'К управлению сервером'));
            }
        } finally { $this->uiListsView = $previous; }
        return true;
    }

    public function uiListsScreen($text, array $buttons): ?array
    {
        $text = (string) $text;
        $flat = [];
        foreach ($buttons as $row) foreach (is_array($row) ? $row : [] as $button) if (is_array($button)) $flat[] = $button;
        $callbacks = array_column($flat, 'callback_data');
        $has = fn(string $pattern): bool => (bool) preg_grep($pattern, $callbacks);
        $b = fn($label, $callback) => $this->uiListsButton($label, $callback);
        $p = $this->getPacConf();
        $input = (string) ($this->input['callback'] ?? '');

        if ($has('~^/autoScanTimeout$~D')) {
            $deny = count($p['deny'] ?? []); $white = count($p['white'] ?? []);
            $page = $this->uiListsView['page'] ?? (preg_match('~^/(switchScanIp|switchBanIp|switchSilence)$~D', $input) ? 'automation' : 'main');
            if ($page !== 'automation') {
                return $this->uiListsFinish("<b>🛡 Защита входа по IP</b>\n\nАнализ журналов ищет подозрительные обращения к серверу. Блокировки применяются во входном Nginx; исключения пропускаются при анализе.\n\nЗаблокировано: $deny · Исключений: $white\nАвтопроверка: " . (!empty($p['autoscan']) ? 'включена' : 'выключена'), [
                    [$b('🔎 Найти подозрительные адреса', '/analysisIp')],
                    [$b("🚫 Заблокированные IP ($deny)", '/denyList 0 0')],
                    [$b("✅ Исключения из проверки ($white)", '/denyList 0 1')],
                    [$b('⚙️ Автопроверка и уведомления', '/listsui security automation')],
                    [$b('📋 Журналы сервера', '/logs')],
                ], '/cfgui diagnostics', 'К диагностике');
            }
            $silence = (int) ($p['silence'] ?? 0);
            $modes = ['со звуком', 'без звука', 'выключены'];
            $next = ['🔈 Сделать уведомления беззвучными', '🔇 Выключить уведомления', '🔊 Включить уведомления со звуком'];
            $text = "<b>⚙️ Автопроверка IP</b>\n\nПроверка: " . (!empty($p['autoscan']) ? 'каждые ' . (int) ($p['autoscan_timeout'] ?? 3600) . ' с' : 'выключена')
                . "\nАвтоблокировка: " . (!empty($p['autodeny']) ? 'включена' : 'выключена') . "\nУведомления: " . ($modes[$silence] ?? 'неизвестно')
                . "\n\nБез автоблокировки бот предлагает проверить адреса. С автоблокировкой найденные адреса сразу попадут в блокировку входного Nginx. Возможны ложные срабатывания — доверенные адреса добавляй в исключения.";
            $rows = [[$b('🕒 Задать интервал проверки…', '/autoScanTimeout')]];
            if (!empty($p['autoscan'])) {
                $rows[] = [$b('⏸ Выключить автопроверку', '/switchScanIp')];
                $rows[] = [$b(!empty($p['autodeny']) ? '⏸ Выключить автоблокировку' : '▶️ Включить автоблокировку', '/switchBanIp')];
                $rows[] = [$b($next[$silence] ?? $next[0], '/switchSilence')];
            }
            return $this->uiListsFinish($text, $rows, '/ipMenu', 'К защите по IP');
        }

        if ($has('~^/cleanLog$~D')) {
            $page = $this->uiListsView['page'] ?? 'files';
            if ($page === 'maintenance') {
                $schedule = htmlspecialchars((string) (($p['autocleanlogs'] ?? '') ?: 'выключено'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $this->uiListsFinish("<b>🧹 Очистка журналов</b>\n\nОчистка удаляет записи диагностики. Она не разблокирует IP, не удаляет пользователей и не сбрасывает счётчики трафика.\n\nАвтоочистка: <code>$schedule</code>", [
                    [$b('🗓 Настроить расписание очистки…', '/autoCleanLogs')],
                    [$b('🗑 Очистить все журналы…', '/cleanLog')],
                ], '/listsui logs files 0', 'К журналам');
            }
            $files = array_values(array_filter($buttons, fn($row) => !empty($row[0]['callback_data']) && preg_match('~^/getLog \d+$~D', $row[0]['callback_data'])));
            $limit = 6; $pages = max(1, (int) ceil(count($files) / $limit));
            $offset = min(max(0, (int) ($this->uiListsView['offset'] ?? 0)), $pages - 1);
            $rows = [];
            foreach (array_slice($files, $offset * $limit, $limit) as $row) {
                $download = $row[0];
                $name = preg_replace('~^\d+\s+~', '', $download['text']);
                $download['text'] = '📥 ' . $name;
                $rows[] = [$download];
                foreach (array_slice($row, 1) as $clear) { $clear['text'] = '🧹 Очистить ' . $name; $rows[] = [$clear]; }
            }
            if ($pages > 1) $rows[] = [$b('⬅️ Файлы', '/listsui logs files ' . max(0, $offset - 1)),
                $b(($offset + 1) . " / $pages", '/listsui logs files ' . $offset),
                $b('Файлы ➡️', '/listsui logs files ' . min($pages - 1, $offset + 1))];
            $rows[] = [$b('🧹 Общая очистка и расписание', '/listsui logs maintenance')];
            return $this->uiListsFinish("<b>📋 Журналы сервера</b>\n\n📥 скачивает файл для диагностики. 🧹 удаляет содержимое выбранного файла. Сначала сохрани журнал, если он нужен для разбора ошибки.\n\nФайлов: " . count($files), $rows, '/cfgui diagnostics', 'К диагностике');
        }

        if ($has('~^/addCommunityFilter$~D')) {
            $page = $this->uiListsView['page'] ?? 'main';
            if (preg_match('~^/(change|delete)includelist ~', $input)) $page = 'entries';
            if ($page === 'main') {
                return $this->uiListsFinish("<b>🔗 PAC · правила прокси для клиента</b>\n\nPAC сообщает совместимому приложению, какие адреса отправлять через уже настроенный прокси. Сам файл не создаёт VPN-подключение.\n\nВыбери, что нужно сделать.", [
                    [$b('🔗 Получить ссылки на PAC', '/listsui pac links')],
                    [$b('🌐 Управлять своими доменами', '/listsui pac entries 0')],
                    [$b('📚 Подключить готовые списки', '/listsui pac sources')],
                    [$b('🧭 Остальные правила маршрутизации', '/routes')],
                ], '/ui admin', 'К управлению сервером');
            }
            if ($page === 'links') {
                $rows = [];
                foreach ($flat as $button) if (isset($button['url']) || isset($button['web_app'])) { $button['text'] = '🔗 ' . $button['text']; $rows[] = [$button]; }
                $header = "<b>🔗 Ссылки на PAC</b>\nОбычный PAC направляет выбранные адреса через прокси. Reverse PAC использует обратный выбор. Параметры адреса и порта прокси в ссылке должны соответствовать клиенту.\n\n";
                return $this->uiListsFinish($this->uiListsPrefix($header, $text), $rows, '/listsui pac main', 'К задачам PAC');
            }
            if ($page === 'sources') return $this->uiListsFinish("<b>📚 Готовые списки доменов</b>\n\nВыбор добавит или обновит домены из внешнего источника. После изменения PAC будет пересобран. Содержимое источника может меняться.", [
                [$b('➕ Добавить Community Antifilter', '/addCommunityFilter')], [$b('➕ Добавить ru-bundle', '/addLegizFilter')],
            ], '/listsui pac main', 'К задачам PAC');
            // Entries/tools are rendered by the same list adapter below.
        }

        if ($has('~^/xtlsblock$~D') && $has('~^/xtlswarp$~D')) {
            $labels = ['/xtlsblock' => '🚫 Блокировка доменов на сервере', '/xtlswarp' => '☁️ Домены через WARP-прокси',
                '/xtlsproxy' => '🌐 Домены через прокси / PAC', '/xtlssubnet' => '📍 IP-подсети',
                '/xtlsprocess' => '💻 Процессы на компьютере', '/xtlsapp' => '📱 Android-приложения', '/xtlsrulesset' => '📚 Наборы правил по ссылке'];
            $rows = [];
            foreach ($flat as $button) if (isset($labels[$button['callback_data'] ?? ''])) { $button['text'] = $labels[$button['callback_data']]; $rows[] = [$button]; }
            return $this->uiListsFinish("<b>🧭 Правила маршрутизации</b>\n\nВыбери, по чему направлять трафик.\n\nБлокировка доменов и список WARP меняют правила Xray на сервере. Остальные списки используются в PAC и выдаваемых конфигурациях; поддержка зависит от клиента.\n\nДля готовых наборов Eternal используй «Подключить устройство».", $rows, '/xrui templates', 'К ссылкам и шаблонам');
        }

        $type = null;
        foreach ($callbacks as $callback) if (preg_match('~^/(?:include|deleteYes) (includelist|blocklist|warplist|packagelist|processlist|subnetlist|rulessetlist)$~D', $callback, $m)) { $type = $m[1]; break; }
        if ($type !== null) {
            $label = $this->uiListsTypes()[$type][0];
            $pac = $has('~^/addCommunityFilter$~D') || ($type === 'includelist' && $has('~^/pacMenu \d+$~D') && !$has('~^/routes$~D'));
            $origin = $pac ? 'pac' : 'routes';
            $currentPage = 0;
            foreach ($callbacks as $callback) if (preg_match('~^/change' . $type . ' \d+ (\d+)$~D', $callback, $m)) { $currentPage = (int) $m[1]; break; }
            $back = $pac ? '/listsui pac main' : '/routes';
            if ($has('~^/deleteYes ~')) {
                return $this->uiListsFinish('<b>🗑 Очистить список «' . $label . "»?</b>\n\nБудут удалены все записи этого списка из настроек. Ключи VPN и пользователи сохранятся. Эта операция не удаляет записи журналов. Для серверных списков проверь применение правил после очистки.", [
                    [$b('🗑 Да, удалить все записи', '/deleteYes ' . $type)],
                ], '/listsui list ' . $type . ' ' . $origin . ' 0', 'Отмена — к списку');
            }
            $tools = ($this->uiListsView['page'] ?? '') === 'tools';
            $entries = array_values($p[$type] ?? []); $names = array_keys($p[$type] ?? []);
            $rows = [];
            foreach ($flat as $button) {
                $cb = $button['callback_data'] ?? '';
                if ($tools) {
                    $labels = ['/exportList ' . $type => '📥 Скачать список CSV', '/importList ' . $type => '📤 Заменить список из CSV…', '/deleteAll ' . $type => '🗑 Очистить весь список…'];
                    if (isset($labels[$cb])) { $button['text'] = $labels[$cb]; $rows[] = [$button]; }
                    continue;
                }
                if ($cb === '/include ' . $type) { $button['text'] = '➕ Добавить запись…'; $rows[] = [$button]; }
                elseif (preg_match('~^/change' . $type . ' (\d+) \d+$~D', $cb, $m)) {
                    $index = (int) $m[1];
                    $name = $names[$index] ?? preg_replace('~^\S+\s+~u', '', $button['text']);
                    $button['text'] = (!empty($entries[$index]) ? '⏸ Выключить: ' : '▶️ Включить: ') . $name;
                    $rows[] = [$button];
                } elseif (preg_match('~^/delete' . $type . ' (\d+) \d+$~D', $cb, $m)) {
                    $button['text'] = '🗑 Удалить: ' . ($names[(int) $m[1]] ?? 'запись'); $rows[] = [$button];
                } elseif (preg_match('~^/(pacMenu|xtlsproxy|xtlsblock|xtlswarp|xtlsapp|xtlsprocess|xtlssubnet|xtlsrulesset) (\d+)$~D', $cb, $m)) {
                    $old = (string) $button['text'];
                    $button['text'] = $old === '<<' ? '⬅️ Предыдущая страница' : ($old === '>>' ? 'Следующая страница ➡️' : '📃 Страница ' . $old);
                    $button['callback_data'] = '/listsui list ' . $type . ' ' . $origin . ' ' . $m[2]; $rows[] = [$button];
                }
            }
            $help = match ($type) {
                'blocklist' => 'Включённые домены блокируются правилами Xray на сервере. Добавление, переключение и удаление записи пересобирают правила и перезапускают Xray.',
                'warplist' => 'Включённые домены направляются в отдельный старый WARP-прокси. Это не режим «Eternal · WARP-выход». Служба WARP-прокси должна работать. Изменение записи пересобирает правила и перезапускает Xray.',
                'packagelist' => 'Здесь идентификаторы Android-приложений, например org.telegram.messenger. Правила учитываются в поддерживающих их клиентских конфигурациях.',
                'processlist' => 'Здесь имена процессов приложений. Правила работают только в клиентах и шаблонах с поддержкой маршрутизации по процессам.',
                'subnetlist' => 'Здесь IP-подсети в формате CIDR. Они добавляются в выдаваемые клиентские конфигурации; уже импортированный файл нужно обновить.',
                'rulessetlist' => 'Это удалённые наборы правил для поддерживающих их клиентов. В записи задаются выход, интервал обновления и URL.',
                default => 'Список используется для PAC и клиентских правил по доменам. Изменение пересобирает PAC; обнови файл или подписку в клиенте.',
            };
            $heading = $tools ? '📂 Импорт, экспорт и очистка' : '🌐 ' . $label;
            $body = '<b>' . $heading . "</b>\n\n" . ($tools ? 'CSV содержит записи и их состояние. Импорт полностью заменяет текущий список данными из файла. Перед заменой можно скачать текущую версию. Очистка удаляет записи целиком.' : $help . "\n\nВключено: " . count(array_filter($entries)) . ' из ' . count($entries) . '. Кнопки показывают действие при нажатии.');
            if ($type === 'rulessetlist' && !$tools) $body = $this->uiListsPrefix($body . "\n\n", $text);
            if (!$tools) $rows[] = [$b('📂 Импорт, экспорт и очистка', '/listsui tools ' . $type . ' ' . $origin . ' ' . $currentPage)];
            return $this->uiListsFinish($body, $rows, $tools ? '/listsui list ' . $type . ' ' . $origin . ' ' . $currentPage : $back, $tools ? 'К записям списка' : ($pac ? 'К задачам PAC' : 'К видам правил'));
        }

        if ($has('~^/include (white|deny)$~D')) {
            $white = $has('~^/include white$~D');
            $rows = [];
            foreach ($flat as $button) {
                $cb = $button['callback_data'] ?? ''; $old = (string) ($button['text'] ?? '');
                if ($cb === '/ipMenu') continue;
                if (str_starts_with($cb, '/include ')) $button['text'] = '➕ Добавить IP или подсеть…';
                elseif (preg_match('~^/importIps (.+)$~D', $cb, $m)) $button['text'] = '➕ Добавить диапазоны ' . ucfirst($m[1]);
                elseif (preg_match('~^/allowIp (\S+)~', $cb, $m)) $button['text'] = ($white ? '🗑 Убрать исключение: ' : '🔓 Разблокировать: ') . $m[1];
                elseif (str_starts_with($cb, '/cleanDeny')) $button['text'] = $white ? '🗑 Удалить все исключения…' : '🔓 Снять все блокировки…';
                elseif (str_starts_with($cb, '/searchLogs ')) {
                    // The legacy detail handler only accepts a single IPv4, not a CIDR range.
                    if (!preg_match('~^/searchLogs \d+\.\d+\.\d+\.\d+(?: |$)~D', $cb)) continue;
                    $button['text'] = '🔎 Проверить ' . $old;
                }
                elseif (str_starts_with($cb, '/denyList ')) $button['text'] = $old === '<<' ? '⬅️ Предыдущие IP' : ($old === '>>' ? 'Следующие IP ➡️' : '📃 Страница ' . $old);
                $rows[] = [$button];
            }
            return $this->uiListsFinish($white
                ? "<b>✅ Исключения из проверки IP</b>\n\nЭти IP/подсети разрешены во входном Nginx и исключены из поиска подозрительных адресов. Удаление исключения само по себе не добавляет блокировку."
                : "<b>🚫 Заблокированные IP</b>\n\nВходной Nginx отклоняет обращения этих адресов. Разблокировка удаляет IP из этого списка; записи журналов сохраняются.", $rows, '/ipMenu', 'К защите по IP');
        }

        if ($has('~^/denyIp \S+~')) {
            $rows = [];
            foreach ($flat as $button) {
                $cb = $button['callback_data'] ?? '';
                $button['text'] = match (true) {
                    str_starts_with($cb, '/denyIp ') => '🚫 Заблокировать этот IP…',
                    str_starts_with($cb, '/whiteIp ') => '✅ Добавить IP в исключения…',
                    str_starts_with($cb, '/searchIp ') => '📋 Все записи об этом IP',
                    str_starts_with($cb, '/searchSuspiciousIp ') => '🔎 Подозрительные записи об IP',
                    str_starts_with($cb, '/cleanLogs ') => '🗑 Удалить записи об IP из журналов…',
                    str_starts_with($cb, '/analysisIp') => '⬅️ К результатам анализа',
                    str_starts_with($cb, '/denyList') => '⬅️ К списку IP',
                    default => $button['text'],
                };
                $rows[] = [$button];
            }
            return $this->uiListsFinish($this->uiListsPrefix("<b>🔎 Проверка адреса</b>\nБлокировка действует на вход сервера. Исключение разрешает адрес и снимает его блокировку. Очистка журналов лишь удаляет записи.\n\n", $text), $rows, '/ipMenu', 'К защите по IP');
        }

        if ($has('~^/searchLogs \S+ analysisIp~') || (preg_match('~^/analysisIp(?: |$)~', $input) && $has('~^/ipMenu$~D'))) {
            $rows = [];
            foreach ($flat as $button) {
                $cb = $button['callback_data'] ?? ''; if ($cb === '/ipMenu') continue;
                if (str_starts_with($cb, '/searchLogs ')) $button['text'] = '🔎 Открыть ' . $button['text'];
                elseif (str_starts_with($cb, '/analysisIp ')) $button['text'] = $button['text'] === '<<' ? '⬅️ Предыдущие' : ($button['text'] === '>>' ? 'Следующие ➡️' : '📃 Страница ' . $button['text']);
                $rows[] = [$button];
            }
            return $this->uiListsFinish("<b>🔎 Результаты проверки журналов</b>\n\nЭто адреса, похожие на сканеры по записям журналов. Совпадение не доказывает злоупотребление: сначала открой записи адреса.\n\nНайдено: " . ($text === 'empty' ? '0' : $text), $rows, '/ipMenu', 'К защите по IP');
        }

        if (str_starts_with($text, 'suspicious ips found:')) {
            foreach ($buttons as &$row) foreach ($row as &$button) {
                if (str_starts_with($button['callback_data'] ?? '', '/searchLogs ')) $button['text'] = '🔎 Проверить ' . $button['text'];
                elseif (($button['callback_data'] ?? '') === '/analysisIp') $button['text'] = '🔎 Открыть результаты анализа';
            }
            unset($row, $button);
            return $this->uiListsFinish("<b>🔔 Автопроверка IP</b>\nНайдены подозрительные обращения; открой записи для проверки.\n\n" . str_replace(['suspicious ips found:', 'banned:'], ['Найдено:', 'Заблокировано:'], $text), $buttons, '/ipMenu', 'К настройкам проверки');
        }
        if (str_starts_with($text, 'vless: ') && $has('~^/switchXr \d+$~D')) {
            foreach ($buttons as &$row) foreach ($row as &$button) if (str_starts_with($button['callback_data'] ?? '', '/switchXr ')) $button['text'] = '⏸ Отключить доступ пользователя…';
            unset($row, $button);
            return $this->uiListsFinish("<b>🔔 Несколько IP у пользователя VLESS</b>\nЭто уведомление. Пользователь автоматически не отключён.\n\n" . $text, $buttons, '/xrui limits', 'К контролю IP');
        }
        return null;
    }

    public function uiListsMessage(string $text): ?array
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (preg_match('~^/(searchIp|searchSuspiciousIp) (\S+)$~D', $callback, $m)) {
            $title = $m[1] === 'searchIp' ? '📋 Записи об адресе' : '🔎 Подозрительные записи';
            // Keep the source lines intact for diagnostics.
            return $this->uiListsFinish($this->uiListsPrefix('<b>' . $title . "</b>\n\n", $text), [], '/searchLogs ' . $m[2], 'К действиям с этим IP');
        }
        $replyCallback = $_SESSION['reply'][$this->input['reply'] ?? 0]['callback'] ?? '';
        if ($callback !== '/checkurl' && $replyCallback !== 'urlcheck') return null;
        if ($text === 'no file, update pac') return $this->uiListsFinish('PAC ещё не создан. Сначала добавь домены или готовый список и дождись сборки.', [], '/pacMenu 0', 'К PAC');
        if (preg_match('~^(.*)\n(not match|match)$~sD', $text, $m)) {
            $intro = $m[2] === 'match' ? 'Адрес совпадает с правилом в PAC.' : 'Адрес не найден в правилах PAC.';
            return $this->uiListsFinish($intro . "\nЭто проверка списка, а не доступности сайта.\n\n" . $m[1], [], '/pacMenu 0', 'К PAC');
        }
        return null;
    }

    public function uiListsPrompt(string $text): ?string
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!str_starts_with($text, '@')) return null;
        if ($callback === '/autoScanTimeout') return 'Введи интервал проверки журналов, например 1 hour или 30 minutes. Проверка будет включена. Чтобы выключить её, вернись к «Автопроверка и уведомления» и нажми «Выключить автопроверку».';
        if ($callback === '/autoCleanLogs') return 'Введи начало и период очистки через /, например now / 7 days. По этому расписанию записи журналов будут удаляться. Перед включением скачай нужные файлы. Чтобы выключить автоочистку, отправь 0.';
        if ($callback === '/checkurl') return 'Введи URL сайта, например https://example.org. Бот проверит совпадение с доменами PAC; доступность сайта через VPN эта проверка не измеряет.';
        if ($callback === '/addips') return 'Введи IP-подсети через запятую, например 192.0.2.0/24, 198.51.100.0/24. Будет создан профиль WireGuard для этих маршрутов.';
        if (preg_match('~^/(reverse|subzones|exclude)(?: |$)~', $callback, $m)) return match ($m[1]) {
            'reverse' => 'Введи домены обратного PAC через запятую, без https:// и путей. Пример: example.org, example.net.',
            'subzones' => 'Введи домены с учётом поддоменов через запятую, без схемы и пути. Они попадут в список подзон PAC.',
            default => 'Введи регулярное выражение для исключения адресов из PAC. Проверь выражение заранее: слишком широкое совпадение может исключить лишние домены.',
        };
        if (preg_match('~^/importList (\w+)$~D', $callback)) return 'Отправь CSV-файл списка в ответ на это сообщение. Формат строк: запись;состояние (1 — включено, 0 — выключено). Текущий список будет полностью заменён содержимым файла. Для блокировки доменов и WARP также пересоберутся серверные правила и перезапустится Xray.';
        if (preg_match('~^/include (\w+)$~D', $callback, $m)) return match ($m[1]) {
            'white' => 'Введи доверенные IP или IPv4-подсети через запятую. Пример: 192.0.2.5, 198.51.100.0/24. Они будут разрешены во входном Nginx и исключены из проверки.',
            'deny' => 'Введи IP или IPv4-подсети через запятую. Входной Nginx будет блокировать эти адреса после применения. Не добавляй собственный адрес управления.',
            'packagelist' => 'Введи идентификаторы Android-приложений через запятую, например org.telegram.messenger. Правила применяются в поддерживающих их клиентах.',
            'processlist' => 'Введи имена процессов через запятую, например firefox.exe, telegram.exe. Клиент и шаблон должны поддерживать правила по процессам.',
            'subnetlist' => 'Введи IP-подсети в формате CIDR через запятую, например 192.0.2.0/24. Затем обнови конфигурацию клиента.',
            'rulessetlist' => 'Введи выход, интервал и URL: proxy:1h:https://example.org/rules.srs. Выход: direct, block, proxy или имя своего outbound. Для mihomo можно указать behavior: proxy:domain:1h:https://example.org/rules.yaml. Формат набора должен подходить клиенту.',
            'blocklist' => 'Введи домены для блокировки через запятую, без https:// и путей. Добавление изменит правила Xray на сервере и перезапустит Xray.',
            'warplist' => 'Введи домены через запятую для старого серверного WARP-прокси. Служба WARP-прокси должна работать. Изменение перезапустит Xray; это не набор «Eternal · WARP-выход».',
            default => 'Введи домены через запятую, без https:// и путей, например example.org, example.net. Список PAC будет пересобран.',
        };
        return null;
    }
}
