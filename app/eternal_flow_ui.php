<?php

trait EternalFlowUi
{
    public function uiFlowScreen($text, array $buttons): array
    {
        foreach (['uiProfilesScreen', 'uiWgScreen', 'uiListsScreen', 'uiServicesScreen'] as $adapter) {
            $screen = $this->{$adapter}($text, $buttons);
            if ($screen !== null) return [$this->uiReadableFields($screen[0]), $screen[1]];
        }
        [$text, $buttons] = $this->uiLegacyScreen($text, $buttons);
        return [$this->uiReadableFields($text), $buttons];
    }

    public function uiFlowPrompt(string $text): string
    {
        foreach (['uiProfilesPrompt', 'uiWgPrompt', 'uiListsPrompt', 'uiServicesPrompt'] as $adapter) {
            $result = $this->{$adapter}($text);
            if ($result !== null) return $result;
        }
        return $this->uiLegacyPrompt($text);
    }

    private function uiReadableFields(string $text): string
    {
        // Never rewrite configuration blocks, URLs, keys or values.
        $parts = preg_split('~(<pre\b[^>]*>.*?</pre>|<code\b[^>]*>.*?</code>)~su', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $labels = ['server'=>'Сервер', 'password'=>'Пароль', 'passwd'=>'Пароль', 'Login'=>'Логин', 'Pass'=>'Пароль',
            'domain'=>'Домен', 'fake domain'=>'Домен маскировки', 'transport'=>'Транспорт', 'status'=>'Состояние',
            'safesearch'=>'Безопасный поиск', 'allowed clients'=>'Разрешённые клиенты', 'nameserver'=>'DNS',
            'method'=>'Метод шифрования', 'plugin'=>'Плагин', 'xray config'=>'Конфигурация Xray',
            'sing-box config'=>'Конфигурация sing-box', 'mihomo config'=>'Конфигурация mihomo'];
        foreach ($parts as $i => &$part) {
            if ($i % 2) continue;
            foreach ($labels as $from => $to) $part = preg_replace('~(^|\n)' . preg_quote($from, '~') . ':~u', '$1' . $to . ':', $part);
            $part = preg_replace('~(^|\n)Menu -> ~u', '$1Меню → ', $part);
        }
        unset($part);
        return implode('', $parts);
    }

    public function uiFitCallbacks(array $rows): array
    {
        foreach ($rows as &$row) foreach ($row as &$button) {
            $callback = $button['callback_data'] ?? '';
            if (strlen($callback) <= 64) continue;
            $token = bin2hex(random_bytes(10));
            $_SESSION['ui_callbacks'][$token] = ['callback'=>$callback, 'user'=>(string) ($this->input['from'] ?? ''),
                'time'=>time(), 'wg'=>$this->wg ?? null];
            $button['callback_data'] = '/menuref ' . $token;
        }
        unset($row, $button);
        if (count($_SESSION['ui_callbacks'] ?? []) > 128) $_SESSION['ui_callbacks'] = array_slice($_SESSION['ui_callbacks'], -128, null, true);
        return $rows;
    }

    public function uiFlowPrepareAction(): bool
    {
        $callback = (string) ($this->input['callback'] ?? '');
        if (!str_starts_with($callback, '/menuref ')) return false;
        $token = substr($callback, 9);
        $reference = $_SESSION['ui_callbacks'][$token] ?? null;
        if (!$reference || $reference['user'] !== (string) ($this->input['from'] ?? '') || time() - $reference['time'] > 1800 || $this->uiMode() !== 'simple') {
            $this->uiShow(['Это меню устарело. Открой нужный раздел заново.', [[['text'=>'🏠 На главную','callback_data'=>'/menu']]]]);
            return true;
        }
        if ($reference['wg'] !== null) $this->wg = (int) $reference['wg'];
        $this->input['callback'] = $reference['callback'];
        return false;
    }

    public function uiExtraReview(string $callback, array $p, array $x): ?array
    {
        $title = $effect = $current = '';
        $back = '/ui admin';
        if (preg_match('~^/(switchClient|delete) (\d+)(?:_(-?\d+))?$~D', $callback, $m)) {
            $client = $this->readClients()[(int) $m[2]] ?? null;
            if (!$client) return null;
            $name = $this->getName($client['interface']);
            $title = $m[1] === 'delete' ? 'Удалить устройство WireGuard' : (!empty($client['# off']) ? 'Включить' : 'Отключить') . ' устройство WireGuard';
            $current = 'Сервер ' . ($this->getInstanceWG() === 'wg1' ? '2' : '1') . ' · ' . $name;
            $effect = 'Изменится доступ выбранного устройства, его таймер будет снят. При удалении профиль нужно будет создать заново. Служба WireGuard перезапустится.';
            $back = '/wgui access ' . ($this->getInstanceWG() === 'wg1' ? '1' : '0') . ' ' . $m[2] . '_' . ($m[3] ?? 0);
        } elseif (preg_match('~^/(switchTorrent|switchExchange) (\d+)$~D', $callback, $m)) {
            $key = $m[1] === 'switchTorrent' ? 'blocktorrent' : 'exchange';
            $blocked = !empty($p[$this->getInstanceWG(1) . $key]);
            $title = ($blocked ? 'Разрешить ' : 'Запретить ') . ($key === 'blocktorrent' ? 'торренты' : 'связь между VPN-клиентами');
            $effect = 'Изменится сетевая политика выбранного WireGuard-сервера. Правила фильтрации будут применены немедленно.';
            $back = '/wgui routing ' . ($this->getInstanceWG() === 'wg1' ? '1' : '0') . ' ' . $m[2];
        } elseif (preg_match('~^/switchAwg3 (-?\d+) ([01]) ok$~D', $callback, $m)) {
            $title = 'Переключить поколение AmneziaWG на сервере ' . ((int) $m[2] + 1);
            $effect = 'Параметры маскировки выбранного сервера будут пересозданы, WireGuard перезапустится. Все его устройства потребуют новых профилей; версия клиента должна поддерживать выбранное поколение.';
            $current = 'Сейчас: ' . (!empty($p[$this->getInstanceWG(1) . 'awg3']) ? 'AmneziaWG 3.1' : 'AmneziaWG 1.5');
            $back = '/wgui protocol ' . $m[2] . ' ' . $m[1];
        } elseif ($callback === '/generateSecret') {
            $title = 'Создать новый секрет Telegram-прокси';
            $effect = 'Секрет MTProto изменится, служба перезапустится. Старые ссылки Telegram-прокси перестанут работать; устройствам потребуется новая ссылка.';
            $back = '/mtproto';
        } elseif ($callback === '/offWarp') {
            $title = 'Переключить старый WARP-прокси';
            $effect = 'Это прежняя отдельная служба WARP-прокси: при выключении её регистрация удаляется, при включении создаётся заново. Серверный «Eternal · WARP-выход» этим действием не переключается.';
            $back = '/warp';
        } elseif ($callback === '/applyupdatebot') {
            $title = 'Применить обновление бота';
            $effect = 'Код проекта будет обновлён из выбранной ветки, после чего бот поставит перезапуск в очередь. Соединения могут временно прерваться.';
            $back = '/menu update';
        } elseif ($callback === '/switchBanIp') {
            $title = (!empty($p['autodeny']) ? 'Выключить' : 'Включить') . ' автоматическую блокировку IP';
            $effect = 'При работе анализатора новые подозрительные IP могут добавляться в блокировки Nginx. Уже заблокированные адреса этим переключателем не очищаются.';
            $back = '/ipMenu';
        } elseif (preg_match('~^/cleanDeny(?: ([01]))?$~D', $callback, $m)) {
            $white = !empty($m[1]);
            $title = $white ? 'Очистить исключения IP' : 'Очистить блокировки IP';
            $effect = $white ? 'Адреса потеряют исключение из проверки и могут снова блокироваться анализатором.' : 'Все адреса из списка блокировок будут удалены. Nginx перечитает конфигурацию.';
            $back = '/denyList 0 ' . ($white ? '1' : '0');
        } elseif (preg_match('~^/(denyIp|whiteIp|allowIp) (\S+)(?: .*)?$~D', $callback, $m)) {
            $title = ['denyIp'=>'Заблокировать адрес', 'whiteIp'=>'Добавить адрес в исключения', 'allowIp'=>'Удалить адрес из списка'][$m[1]];
            $current = 'Адрес: ' . $m[2];
            $effect = 'Изменятся серверные списки доступа по IP, Nginx перечитает правила. Блокировка может закрыть этому адресу доступ к входам сервера; удаление из исключений вернёт его под проверку.';
            $back = '/ipMenu';
        } elseif (preg_match('~^/(clearLog|delLog) (\d+)$~D', $callback, $m) || $callback === '/cleanLog') {
            $title = $callback === '/cleanLog' ? 'Очистить все журналы' : ($m[1] === 'delLog' ? 'Удалить файл журнала' : 'Очистить журнал');
            $effect = 'Сохранённые строки журнала будут потеряны. Это не снимает блокировки IP и не отключает пользователей.';
            $back = '/logs';
        } elseif (preg_match('~^/cleanLogs (\S+)(?: (1))?$~D', $callback, $m)) {
            $title = 'Удалить строки об IP из журналов';
            $current = 'Адрес: ' . $m[1];
            $effect = 'Из журналов будут удалены записи с этим адресом. Его блокировка и VPN-доступ не изменятся.';
            $back = '/ipMenu';
        } elseif (preg_match('~^/(change|delete)(blocklist|warplist) (\d+)(?: -?\d+)?$~D', $callback, $m)) {
            $title = ($m[1] === 'delete' ? 'Удалить правило' : 'Переключить правило') . ($m[2] === 'blocklist' ? ' блокировки домена' : ' маршрута через WARP-прокси');
            $effect = 'Изменится список правил. Бот пересоберёт серверную маршрутизацию Xray и перезапустит его; активные соединения могут прерваться. Правила также учитываются в выдаваемых клиентских файлах.';
            $back = $m[2] === 'blocklist' ? '/xtlsblock' : '/xtlswarp';
        } elseif (preg_match('~^/delTemplate (v2ray|sing|clash) (\S+)$~D', $callback, $m)) {
            $title = 'Удалить шаблон конфигурации';
            $current = 'Шаблон: ' . (base64_decode($m[2], true) ?: 'не найден');
            $effect = 'Шаблон исчезнет из списка. Уже скачанные файлы сохранятся; выдача пользователям, которые его выбирали, может измениться.';
            $back = '/templates ' . $m[1];
        } else return null;
        return compact('title', 'effect', 'current', 'back');
    }
}
