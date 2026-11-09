<?php

// WireGuard menus are scoped to a concrete instance. Viewing them never writes PAC.
trait EternalWgUi
{
    private string $uiWgPage = '';

    private function uiWgInstance(): int { return $this->getInstanceWG() === 'wg1' ? 1 : 0; }
    private function uiWgEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function uiWgButton(string $text, string $callback): array { return ['text'=>$text, 'callback_data'=>$callback]; }
    private function uiWgRoute(string $page, $arg = 0): string { return '/wgui ' . $page . ' ' . $this->uiWgInstance() . ' ' . $arg; }

    private function uiWgScopedCallback(string $callback): bool
    {
        return (bool) preg_match('~^/(?:menu (?:wg|client|addpeer) -?\d+(?:_-?\d+)?|(?:switchAmnezia|resetAmnezia|switchTorrent|switchExchange|switchEndpoint|defaultDNS|defaultMTU|addSubnets) -?\d+|switchAwg3 -?\d+ [01](?: ok)?|(?:rename|timer|switchClient|delete|dns|deletedns|changeAllowedIps|changeMTU) \d+(?:_-?\d+)?|(?:qr|download) \d+|changeIps (?:all|subnet|list|proxy)_\d+(?:_-?\d+)?|(?:subnet|subnetAdd|subnetDelete) -?\d+(?:_-?\d+){0,3}|add|add_ips|calc)$~D', $callback);
    }

    private function uiWgBind(array $rows): array
    {
        foreach ($rows as &$row) foreach ($row as &$button) {
            $callback = $button['callback_data'] ?? '';
            if (!$this->uiWgScopedCallback($callback)) continue;
            $bound = '/wgdo ' . $this->uiWgInstance() . ' ' . rtrim(strtr(base64_encode($callback), '+/', '-_'), '=');
            if (strlen($bound) > 64) throw new RuntimeException('WireGuard callback exceeds Telegram limit');
            $button['callback_data'] = $bound;
        }
        return $rows;
    }

    // Run first in Bot::action, before action-review guards. true means handled.
    public function uiWgPrepareAction(): bool
    {
        $reply = $this->input['reply'] ?? null;
        if ($reply && isset($_SESSION['ui_wg_prompts'][$reply])) {
            $prompt = $_SESSION['ui_wg_prompts'][$reply];
            unset($_SESSION['ui_wg_prompts'][$reply]);
            $this->wg = (int) $prompt['instance'];
            if (time() - (int) $prompt['time'] > 300) {
                unset($_SESSION['reply'][$reply]);
                $this->uiShow(['Время ввода истекло. Открой настройку устройства ещё раз.', [[$this->uiWgButton('⬅️ К WireGuard / Amnezia', $this->uiWgRoute('root'))]]]);
                return true;
            }
        }
        $callback = (string) ($this->input['callback'] ?? '');
        if (!str_starts_with($callback, '/wgdo ')) return false;
        if (!preg_match('~^/wgdo ([01]) ([A-Za-z0-9_-]+)$~D', $callback, $m)) {
            $this->uiShow(['Недействительная кнопка. Открой меню заново.', [[$this->uiWgButton('🏠 На главную', '/menu')]]]);
            return true;
        }
        $decoded = base64_decode(strtr($m[2], '-_', '+/'), true);
        if (!is_string($decoded) || !$this->uiWgScopedCallback($decoded)) {
            $this->uiShow(['Недействительная кнопка. Открой меню заново.', [[$this->uiWgButton('🏠 На главную', '/menu')]]]);
            return true;
        }
        $this->wg = (int) $m[1];
        if ($this->uiMode() === 'classic') { $this->menu('wg', 0); return true; }
        $this->input['callback'] = $decoded;
        return false;
    }

    public function uiWgAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if ($this->uiMode() !== 'classic' && preg_match('~^/changeWG ([01])$~D', $callback, $m)) {
            $this->wg = (int) $m[1];
            $this->menu('wg', 0);
            return true;
        }
        if (!str_starts_with($callback, '/wgui ')) return false;
        if (!preg_match('~^/wgui (root|devices|defaults|protocol|routing|peer|config|access|network) ([01]) (-?\d+(?:_-?\d+)?)$~D', $callback, $m)) {
            $this->uiShow(['Раздел не найден. Открой меню заново.', [[$this->uiWgButton('🏠 На главную', '/menu')]]]);
            return true;
        }
        $this->wg = (int) $m[2];
        $this->uiWgPage = $m[1];
        try {
            $peer = in_array($m[1], ['peer', 'config', 'access', 'network'], true);
            if ($peer && !preg_match('~^\d+_-?\d+$~D', $m[3])) {
                $this->uiShow(['Устройство не найдено.', [[$this->uiWgButton('⬅️ К устройствам', $this->uiWgRoute('devices'))]]]);
            } else $this->menu($peer ? 'client' : 'wg', $peer ? $m[3] : (int) $m[3]);
        } finally { $this->uiWgPage = ''; }
        return true;
    }

    public function uiWgScreen($text, array $buttons): ?array
    {
        if (!is_string($text)) return null;
        $flat = [];
        foreach ($buttons as $row) foreach ($row as $button) if (isset($button['callback_data'])) $flat[$button['callback_data']] = $button;
        $has = fn($pattern) => (bool) preg_grep($pattern, array_keys($flat));
        $b = fn($label, $cb) => $this->uiWgButton($label, $cb);
        $home = [$b('🏠 На главную', '/menu')];
        $p = $this->getPacConf();
        $prefix = $this->getInstanceWG(1);
        $number = $this->uiWgInstance() + 1;
        $protocol = !empty($p[$prefix . 'amnezia']) ? (!empty($p[$prefix . 'awg3']) ? 'AmneziaWG 3.1' : 'AmneziaWG') : 'WireGuard';
        $title = '🔐 ' . $protocol . ' · сервер ' . $number;
        if ($has('~^/switchAmnezia ~')) {
            $match = [];
            preg_match('~^/switchAmnezia (-?\d+)$~D', (string) current(preg_grep('~^/switchAmnezia ~', array_keys($flat))), $match);
            $page = (int) ($match[1] ?? 0);
            $part = $this->uiWgPage ?: (str_starts_with((string) ($this->input['callback'] ?? ''), '/delete ') ? 'devices' : 'root');
            $back = [$b('⬅️ К разделам сервера ' . $number, $this->uiWgRoute('root', $page))];
            $rows = [];
            $body = '<b>' . $title . "</b>\n\n";
            if ($part === 'devices') {
                $body .= "<b>Устройства</b>\nОткрой устройство для файла, QR-кода или изменения доступа. Статус ниже отражает последнее рукопожатие, а не результат проверки интернета.\n\n"
                    . preg_replace('~^Menu ->[^\n]*\n*~u', '', $text);
                foreach ($buttons as $row) {
                    $selected = [];
                    foreach ($row as $button) {
                        $cb = $button['callback_data'] ?? '';
                        if (preg_match('~^/menu client (\d+_-?\d+)$~D', $cb, $m)) {
                            $button['text'] = '📱 ' . $button['text'];
                            $button['callback_data'] = $this->uiWgRoute('peer', $m[1]);
                            $selected[] = $button;
                        } elseif (preg_match('~^/menu wg (-?\d+)$~D', $cb, $m) && count($row) > 2) {
                            $button['callback_data'] = $this->uiWgRoute('devices', $m[1]);
                            if ($button['text'] === '<<') $button['text'] = '⬅️ Ранее';
                            if ($button['text'] === '>>') $button['text'] = 'Далее ➡️';
                            $selected[] = $button;
                        }
                    }
                    if ($selected) $rows[] = $selected;
                }
                $rows[] = [$b('➕ Добавить устройство', '/menu addpeer ' . $page)];
                $rows[] = [$b('🔄 Обновить список', $this->uiWgRoute('devices', $page))];
            } elseif ($part === 'defaults') {
                $dns = ($p[$prefix . 'dns'] ?? '') ?: ($this->dns ?? '');
                $mtu = ($p[$prefix . 'mtu'] ?? '') ?: ($this->mtu ?? 1350);
                $body .= "<b>Параметры экспорта профилей</b>\nDNS по умолчанию: <code>" . $this->uiWgEscape($dns) . "</code>\nMTU по умолчанию: <code>" . $this->uiWgEscape($mtu) . "</code>\nАдрес входа: " . (!empty($p[$prefix . 'endpoint']) ? 'IP сервера' : 'домен сервера')
                    . "\n\nDNS разрешает имена сайтов. MTU задаёт размер пакета туннеля. При выдаче профиля эти значения подставляются, если у устройства нет собственных. Адрес входа меняется при каждом экспорте. На телефоне изменения появятся после повторного импорта.";
                $rows = [[$b('✏️ Изменить DNS по умолчанию…', '/defaultDNS ' . $page)], [$b('✏️ Изменить MTU по умолчанию…', '/defaultMTU ' . $page)],
                    [$b(!empty($p[$prefix . 'endpoint']) ? '🌐 Использовать домен при экспорте' : '📍 Использовать IP при экспорте', '/switchEndpoint ' . $page)]];
            } elseif ($part === 'protocol') {
                $body .= "<b>Протокол и маскировка</b>\nСейчас: " . $protocol . "\n\nОбычный WireGuard и Amnezia используют разные параметры. После смены режима или ключей маскировки устройствам потребуется заново импортировать конфигурации. AWG 3.1 требует клиента с поддержкой этой версии.";
                $rows[] = [$b(!empty($p[$prefix . 'amnezia']) ? '🔀 Перейти на обычный WireGuard…' : '🔀 Перейти на AmneziaWG…', '/switchAmnezia ' . $page)];
                $rows[] = [$b(!empty($p[$prefix . 'amnezia']) && !empty($p[$prefix . 'awg3']) ? '⏸ Выключить режим AWG 3.1…' : '▶️ Включить режим AWG 3.1…', '/switchAwg3 ' . $page . ' ' . $this->uiWgInstance())];
                if (isset($flat['/resetAmnezia ' . $page])) $rows[] = [$b('🔑 Пересоздать ключи маскировки…', '/resetAmnezia ' . $page)];
            } elseif ($part === 'routing') {
                $body .= "<b>Маршруты и правила доступа</b>\nТорренты: настройка " . (!empty($p[$prefix . 'blocktorrent']) ? 'блокировки включена' : 'блокировки выключена')
                    . "\nСвязь между клиентами: настройка " . (!empty($p[$prefix . 'exchange']) ? 'запрета включена' : 'запрета выключена')
                    . "\n\nЭти переключатели меняют сетевые правила сервера. Список подсетей используется при создании профиля с выбранными маршрутами. Для одного устройства маршруты задаются в его карточке.";
                $rows = [[$b(!empty($p[$prefix . 'blocktorrent']) ? 'Разрешить торренты' : 'Блокировать торренты', '/switchTorrent ' . $page)],
                    [$b(!empty($p[$prefix . 'exchange']) ? 'Разрешить связь между устройствами' : 'Запретить связь между устройствами', '/switchExchange ' . $page)],
                    [$b('📍 Общий список подсетей', '/subnet ' . $page)]];
            } else {
                $body .= 'Устройств: <b>' . count($this->readClients()) . "</b>\n\nДля подключения открой «Устройства». Настройки протокола и маршрутов вынесены отдельно.";
                $rows = [[$b('📱 Устройства и подключение', $this->uiWgRoute('devices', $page))], [$b('➕ Добавить устройство', '/menu addpeer ' . $page)],
                    [$b('⚙️ DNS, MTU и адрес в профилях', $this->uiWgRoute('defaults', $page))], [$b('🔐 Протокол и маскировка', $this->uiWgRoute('protocol', $page))],
                    [$b('🌐 Маршруты и правила доступа', $this->uiWgRoute('routing', $page))]];
                $back = [$b('⬅️ К протоколам', '/ui protocols admin')];
            }
            $rows[] = $back; $rows[] = $home;
            return [$body, $this->uiWgBind($rows)];
        }
        if ($has('~^/switchClient ~')) {
            preg_match('~^/switchClient (\d+)_(\-?\d+)$~D', (string) current(preg_grep('~^/switchClient ~', array_keys($flat))), $m);
            $id = (int) ($m[1] ?? 0); $page = (int) ($m[2] ?? 0); $arg = $id . '_' . $page;
            $client = $this->readClients()[$id] ?? null;
            if (!$client) return ['Устройство уже удалено.', [[$b('⬅️ К устройствам', $this->uiWgRoute('devices', $page))], $home]];
            $name = $this->uiWgEscape($this->getName($client['interface']));
            $body = '<b>📱 ' . $name . "</b>\n" . $title . "\n\nДоступ: " . (!empty($client['# off']) ? 'отключён' : 'включён')
                . "\nСрок: " . $this->uiWgEscape(($client['interface']['## time'] ?? '') ?: 'без ограничения');
            $part = $this->uiWgPage ?: 'peer';
            $back = [$b('⬅️ К устройству', $this->uiWgRoute('peer', $arg))];
            if ($part === 'config') {
                $body = '<b>📥 Подключить устройство · сервер ' . $number . "</b>\nСкачай файл или открой QR-код. После изменения параметров импортируй профиль заново.\n\n" . $text;
                if (mb_strlen(html_entity_decode(strip_tags($body))) > 4096) $body = $text;
                $rows = [[$b('📥 Скачать файл .conf', '/download ' . $id)], [$b('📷 Открыть QR-код', '/qr ' . $id)]];
            } elseif ($part === 'access') {
                $body .= "\n\nОтключение сохраняет профиль, удаление убирает его с сервера. Переключение доступа снимает таймер. Изменение срока включает ранее отключённое устройство.";
                $rows = [[$b(!empty($client['# off']) ? '▶️ Включить доступ…' : '⏸ Отключить доступ…', '/switchClient ' . $arg)],
                    [$b('⏳ Изменить срок действия…', '/timer ' . $arg)], [$b('✏️ Переименовать устройство…', '/rename ' . $arg)], [$b('🗑 Удалить устройство…', '/delete ' . $arg)]];
            } elseif ($part === 'network') {
                $body .= "\n\nМаршруты AllowedIPs: <code>" . $this->uiWgEscape($client['peers'][0]['AllowedIPs'] ?? '') . '</code>'
                    . "\nDNS при экспорте: <code>" . $this->uiWgEscape(($client['interface']['DNS'] ?? '') ?: (($p[$prefix . 'dns'] ?? '') ?: ($this->dns ?? ''))) . '</code>'
                    . (empty($client['interface']['DNS']) ? ' (по умолчанию)' : '')
                    . "\nMTU при экспорте: <code>" . $this->uiWgEscape(($client['interface']['MTU'] ?? '') ?: (($p[$prefix . 'mtu'] ?? '') ?: ($this->mtu ?? 1350))) . '</code>'
                    . (empty($client['interface']['MTU']) ? ' (по умолчанию)' : '')
                    . "\n\nИзменяется сохранённый профиль. Для применения скачай его и импортируй на устройстве заново.";
                $dnsAction = !empty($client['interface']['DNS']) ? '/deletedns ' : '/dns ';
                $rows = [[$b('🌐 Выбрать трафик через VPN', '/changeAllowedIps ' . $arg)],
                    [$b(!empty($client['interface']['DNS']) ? '↩️ Вернуть DNS по умолчанию' : 'Указать внутренний DNS сервера', $dnsAction . $arg)], [$b('✏️ Изменить MTU…', '/changeMTU ' . $arg)]];
            } else {
                $body .= "\n\nВыбери задачу. Файл и QR-код находятся в разделе подключения.";
                $rows = [[$b('📥 Файл и QR-код подключения', $this->uiWgRoute('config', $arg))], [$b('🔐 Доступ, срок и имя', $this->uiWgRoute('access', $arg))],
                    [$b('🌐 Маршруты, DNS и MTU', $this->uiWgRoute('network', $arg))]];
                $back = [$b('⬅️ К списку устройств', $this->uiWgRoute('devices', $page))];
            }
            $rows[] = $back; $rows[] = $home;
            return [$body, $this->uiWgBind($rows)];
        }
        $intro = null;
        $sharedSubnets = $has('~^/subnetAdd ~');
        $awgConfirm = $has('~^/switchAwg3 -?\d+ [01] ok$~D');
        if ($has('~^/add$~D')) $intro = "<b>➕ Новое устройство · выбор маршрутов</b>\nВесь интернет — весь IPv4-трафик через VPN. Свои подсети — только перечисленные адреса. Общий список — сохранённые подсети сервера. После добавления получишь профиль для импорта.";
        elseif ($has('~^/changeIps ~')) $intro = "<b>🌐 Трафик устройства через VPN</b>\nAllowedIPs задаёт адреса, направляемые в туннель. «Весь IPv4» включает интернет, «Свои подсети» — только введённые сети. После изменения скачай профиль заново.";
        elseif ($sharedSubnets) $intro = "<b>📍 Общий список подсетей</b>\nСети в формате CIDR, например 192.168.0.0/16. WireGuard использует список по выбору при создании или изменении профиля. В разделе OpenConnect изменения этого списка сразу применяются с перезапуском OpenConnect.";
        elseif ($awgConfirm) $intro = "<b>⚠️ Смена версии AmneziaWG</b>\nПереключение действует только на указанный сервер. Сначала подготовь совместимые клиенты и возможность заново импортировать их профили.";
        if ($intro === null) {
            $cb = (string) ($this->input['callback'] ?? '');
            if (str_contains($text, 'error status') && preg_match('~^/(?:changeWG|menu wg|wgui) ~', $cb)) {
                return ['<b>' . $title . "</b>\n\nНе удалось получить состояние WireGuard. Это ещё не доказывает, что VPN остановлен.", [[$b('🔄 Повторить проверку', $this->uiWgRoute('root'))], [$b('⬅️ К протоколам', '/ui protocols admin')], $home]];
            }
            return null;
        }
        $rows = [];
        foreach ($buttons as $row) {
            $new = [];
            foreach ($row as $button) {
                $cb = $button['callback_data'] ?? '';
                $old = $button['text'] ?? '';
                if ($cb === '/add') $button['text'] = '➕ Создать · весь IPv4 через VPN';
                elseif ($cb === '/add_ips') $button['text'] = '✏️ Создать · указать свои подсети…';
                elseif (str_starts_with($cb, '/addSubnets ')) $button['text'] = '➕ Создать · общий список подсетей';
                elseif (preg_match('~^/changeIps (all|subnet|list|proxy)_~', $cb, $m)) $button['text'] = ['all'=>'Весь IPv4 через VPN', 'subnet'=>'✏️ Указать свои подсети…', 'list'=>'Использовать общий список подсетей', 'proxy'=>'Только адрес встроенного прокси'][$m[1]];
                elseif ($cb === '/calc') $button['text'] = '🧮 Рассчитать подсети с исключениями…';
                elseif (str_starts_with($cb, '/subnetAdd ')) $button['text'] = '➕ Добавить подсети…';
                elseif (str_starts_with($cb, '/subnetDelete ')) $button['text'] = '🗑 Удалить ' . preg_replace('~^\S+\s+~u', '', $old);
                elseif (preg_match('~^/menu wg (-?\d+)$~D', $cb, $m)) { $button = $b('⬅️ К разделам сервера', $this->uiWgRoute('root', $m[1])); }
                elseif (preg_match('~^/menu client (\d+_-?\d+)$~D', $cb, $m)) { $button = $b('⬅️ К параметрам устройства', $this->uiWgRoute('network', $m[1])); }
                elseif ($cb === '/menu oc') $button = $b('⬅️ К настройкам OpenConnect', '/serviceui oc settings');
                elseif (preg_match('~^/switchAwg3 .* ok$~D', $cb)) $button['text'] = '✅ Переключить и перевыпустить профили';
                elseif ($old === '<<') $button['text'] = '⬅️ Ранее';
                elseif ($old === '>>') $button['text'] = 'Далее ➡️';
                $new[] = $button;
            }
            $rows[] = $new;
        }
        $rows[] = $home;
        $legacy = preg_replace('~^Menu ->[^\n]*(?:\n+|$)~u', '', $text);
        if ($awgConfirm) $legacy = str_replace('инстансе ' . $this->uiWgInstance(), 'сервере ' . $number, $legacy);
        $body = ($sharedSubnets ? '' : '<b>' . $title . "</b>\n\n") . $intro . ($legacy === '' ? '' : "\n\n" . $legacy);
        if (mb_strlen(html_entity_decode(strip_tags($body))) > 4096) $body = $text;
        return [$body, $this->uiWgBind($rows)];
    }

    public function uiWgPrompt(string $text): ?string
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!preg_match('~^@\S*\s~u', $text)) return null;
        $prompt = match (true) {
            (bool) preg_match('~^/defaultDNS ~', $callback) => 'Введи DNS-серверы через запятую, например 1.1.1.1, 8.8.8.8. Они будут подставляться при экспорте профилей без собственного DNS. Уже импортированные на телефонах профили не изменятся.',
            (bool) preg_match('~^/defaultMTU ~', $callback) => 'Введи MTU по умолчанию — целое число, например 1350. Это размер пакета туннеля. Значение подставляется при экспорте профилей без собственного MTU. Если текущая связь работает, менять его необязательно.',
            (bool) preg_match('~^/changeMTU ~', $callback) => 'Введи MTU этого устройства — целое число, например 1350. Значение 0 вернёт MTU по умолчанию для выбранного сервера. После сохранения скачай и импортируй профиль заново.',
            (bool) preg_match('~^/rename \d+~', $callback) => 'Введи понятное имя устройства, например «Телефон Макса». Поменяется подпись профиля; ключи и доступ сохранятся.',
            (bool) preg_match('~^/timer \d+~', $callback) => 'Введи дату отключения, например 2026-12-31 23:00, или относительный срок +7 days. Значение 0 снимает срок. Сохранение включает отключённое устройство и перезапускает WireGuard.',
            (bool) preg_match('~^/(?:subnetAdd|changeIps subnet_)~', $callback), $callback === '/add_ips' => 'Введи IPv4-подсети через запятую в формате CIDR, например 192.168.0.0/16, 10.0.0.0/8. Только эти сети будут направляться через VPN. Для общих подсетей OpenConnect сохранение также перезапустит его службу.',
            $callback === '/calc' => 'Введи сети и исключения через запятую, например 10.0.0.0/24, -10.0.0.5/32. Минус исключает адрес из диапазона. Калькулятор покажет результат, настройки VPN не изменит.',
            default => null,
        };
        if ($prompt === null) return null;
        if ($callback === '/calc' || str_starts_with($callback, '/subnetAdd ')) return $prompt;
        return '🔐 WireGuard / Amnezia · сервер ' . ($this->uiWgInstance() + 1) . "\n\n" . $prompt;
    }
}
