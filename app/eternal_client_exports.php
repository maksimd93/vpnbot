<?php

// Portable client exports. The existing source builders own endpoint validation,
// owner access checks and the separation between ordinary and WARP-exit users.
trait EternalClientExports
{
    /** @return array{name:string,content:string,mime:string}|array{} */
    public function eternalClientExport(string $client, string $mode, bool $resolve = true): array
    {
        if (!in_array($client, ['v2rayn', 'karing_android', 'karing_ios'], true)
            || !in_array($mode, ['auto', 'exit'], true)) return [];

        $source = $mode === 'exit' ? $this->eternalExitSources($resolve) : $this->eternalAutoSources($resolve);
        if (empty($source['links'])) return [];

        $prefix = $mode === 'exit' ? 'Eternal WARP · ' : 'Eternal · ';
        $links = [];
        foreach ($source['links'] as $tag => $uri) {
            // Keep the scheme, credentials, endpoint, TLS/SNI, transport and XHTTP
            // extra query exactly as supplied. Only the human-readable name changes.
            $links[] = explode('#', $uri, 2)[0] . '#' . rawurlencode($prefix . $tag);
        }
        $app = $client === 'v2rayn' ? 'v2rayN' : 'Karing';
        $name = ($mode === 'exit' ? 'Eternal-Exit-WARP-' : 'Eternal-Auto-') . $app . '.txt';
        return ['name' => $name, 'content' => implode("\n", $links) . "\n", 'mime' => 'text/plain'];
    }

    public function sendEternalAdditionalFiles(string $client, string $mode): bool
    {
        $export = $this->eternalClientExport($client, $mode);
        $chat = $this->input['from'];
        if (!$export) {
            $this->send($chat, 'Этот набор подключений сейчас недоступен. Открой меню и выбери актуальный набор.');
            return false;
        }
        $app = $client === 'v2rayn' ? 'v2rayN' : 'Karing';
        $modeText = $mode === 'exit' ? 'Выход через WARP на сервере Eternal.' : 'Выход через сервер Eternal.';
        $result = $this->sendFile($chat, new CURLStringFile($export['content'], $export['name'], $export['mime']),
            "$app · $modeText Сохрани TXT и импортируй его по инструкции ниже. "
            . 'Файл содержит отдельные узлы; автоматическая группа не создаётся. '
            . 'Выбирай узлы только из нужного набора. Файл содержит данные доступа — передавай его только тем, кому разрешён этот VPN.');
        return !empty($result['ok']);
    }
}
