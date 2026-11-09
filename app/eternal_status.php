<?php

trait EternalBotStatus
{
    // Only GET snapshots, with one deadline per parallel batch. No SSH or exec.
    protected function uiStatusRequests(array $paths): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = array_fill_keys($paths, null);
        try {
            foreach ($paths as $path) {
                $ch = curl_init('http://localhost' . $path);
                curl_setopt_array($ch, [
                    CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT_MS => 300,
                    CURLOPT_TIMEOUT_MS => 1200,
                    CURLOPT_HTTPGET => true,
                ]);
                $handles[$path] = $ch;
                curl_multi_add_handle($multi, $ch);
            }
            $deadline = microtime(true) + 1.4;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK || !$running) break;
                if (curl_multi_select($multi, 0.05) === -1) usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($handles as $path => $ch) {
                if (curl_errno($ch) !== 0 || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) continue;
                $data = json_decode(curl_multi_getcontent($ch), true);
                if (is_array($data)) $results[$path] = $data;
            }
        } finally {
            foreach ($handles as $ch) { curl_multi_remove_handle($multi, $ch); curl_close($ch); }
            curl_multi_close($multi);
        }
        return $results;
    }

    private function uiServiceDefinitions(): array
    {
        $conf = $this->getPacConf();
        return [
            'wg' => ['wireguard', ['amneziawg-go', 'wireguard-go'], !empty($conf['amnezia']) ? 'Amnezia ①' : 'WireGuard ①'],
            'wg1' => ['wireguard1', ['amneziawg-go', 'wireguard-go'], !empty($conf['wg1_amnezia']) ? 'Amnezia ②' : 'WireGuard ②'],
            'xr' => ['xray', ['xray'], 'VLESS / Xray'],
            'hy' => ['hysteria', ['hysteria'], 'Hysteria'],
            'np' => ['naive', ['caddy'], 'NaiveProxy'],
            'oc' => ['openconnect', ['ocserv', 'ocserv-main'], 'OpenConnect'],
            'ss' => ['shadowsocks', ['ssserver'], 'Shadowsocks'],
            'tg' => ['mtproto', ['mtproto-proxy'], 'Telegram'],
            'ad' => ['adguard', ['AdGuardHome'], 'DNS / AdGuard'],
            'dnstt' => ['dnstt', ['dnstt-server'], 'DNSTT'],
            'egress' => ['eternal-egress', ['xray'], 'WARP-выход'],
            'wp' => ['warp', ['warp-svc'], 'WARP-прокси'],
            'up' => ['upstream', ['nginx'], 'Вход 443'],
            'ng' => ['nginx', ['nginx'], 'Веб-сервер'],
            'php' => ['php', ['unitd'], 'Бот'],
            'proxy' => ['proxy', ['sslocal'], 'Прокси подписок'],
            'service' => ['service', ['php'], 'Фоновые задачи'],
        ];
    }

    public function uiServiceStates(): array
    {
        $definitions = $this->uiServiceDefinitions();
        $states = array_fill_keys(array_keys($definitions), 'unknown');
        $containers = $this->uiStatusRequests(['/containers/json?all=1'])['/containers/json?all=1'] ?? null;
        if (!is_array($containers) || !array_is_list($containers)) return $states;
        $byName = [];
        foreach ($containers as $container) {
            if (!is_array($container) || !is_array($container['Names'] ?? null)) return $states;
            foreach ($container['Names'] as $name) {
                if (is_string($name)) $byName[ltrim($name, '/')] = $container;
            }
        }
        $paths = [];
        $checks = [];
        $version = getenv('VER') ?: '2.29';
        foreach ($definitions as $key => [$prefix, $commands]) {
            $name = $prefix . ($key === 'egress' ? '' : '-' . $version);
            $container = $byName[$name] ?? null;
            if ($container === null) { $states[$key] = 'missing'; continue; }
            $state = $container['State'] ?? null;
            if (!is_string($state) || !is_string($container['Status'] ?? '') || !is_string($container['Id'] ?? '')) continue;
            if (in_array($state, ['exited', 'dead', 'created'], true)) { $states[$key] = 'off'; continue; }
            if (in_array($state, ['restarting', 'paused', 'removing'], true)
                || preg_match('/\((unhealthy|health: starting|paused)\)/i', $container['Status'] ?? '')) {
                $states[$key] = 'warning'; continue;
            }
            if ($state !== 'running' || !preg_match('/^[a-f0-9]{12,64}$/D', $container['Id'] ?? '')) continue;
            $path = '/containers/' . $container['Id'] . '/top?ps_args=-o%20pid%2Ccomm';
            $paths[] = $path;
            $checks[$key] = $path;
        }
        $snapshots = $paths ? $this->uiStatusRequests($paths) : [];
        foreach ($checks as $key => $path) {
            $top = $snapshots[$path] ?? null;
            if (!is_array($top) || !is_array($top['Titles'] ?? null) || !is_array($top['Processes'] ?? null)) continue;
            $column = array_search('COMMAND', $top['Titles'], true);
            if ($column === false) continue;
            $commands = [];
            foreach ($top['Processes'] as $row) {
                if (!is_array($row) || !is_string($row[$column] ?? null)) continue 2;
                $commands[] = trim($row[$column]);
            }
            $present = array_intersect($definitions[$key][1], $commands);
            // Kernel WireGuard has no userspace process; don't call that a failure.
            $states[$key] = $present ? 'on' : (in_array($key, ['wg', 'wg1'], true) ? 'unknown' : 'off');
        }
        return $states;
    }

    public function uiStatusSummary(): string
    {
        $states = $this->uiServiceStates();
        $icons = ['on' => '🟢', 'off' => '⚪', 'missing' => '➖', 'warning' => '🟠', 'unknown' => '❔'];
        $items = [];
        foreach ($this->uiServiceDefinitions() as $key => [, , $label]) {
            $items[] = ($icons[$states[$key] ?? 'unknown'] ?? '❔') . ' ' . $label;
        }
        $lines = array_map(fn($row) => implode('  ·  ', $row), array_chunk($items, 2));
        return "<b>📊 Сервисы Eternal</b>\n" . implode("\n", $lines)
            . "\n\n🟢 запущен · ⚪ остановлен · ➖ не установлен\n🟠 требует внимания · ❔ нет данных"
            . "\n<em>Процессы на сервере. Проверка связи с телефона — в «Помощи».</em>"
            . "\nОбновлено: " . date('H:i:s T');
    }
}
