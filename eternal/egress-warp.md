# WARP на выходе Eternal

Отдельный набор `Eternal · WARP-выход` использует только Eternal как личный
сервер. Его VLESS-входы направлены в серверный WARP. Старый `Eternal · Авто`
сохраняет свои UUID, маршруты и настройки XMUX.

В боте: **Hysteria → WARP на выходе Eternal → нужное приложение**.

- LxBox получает `Eternal-Exit-WARP-Android.json`. Импорт через верхний раздел
  резервного копирования, объединение с текущими. Новая папка имеет отдельный
  стабильный ID и уже содержит авто-узел. На главном экране нажать ▶ справа
  от `Eternal · WARP-выход`.
- Exclave получает `Eternal-Exit-WARP-Nodes.txt`. Импортировать файл, создать
  балансировщик типа «Список» только из новых узлов `Eternal WARP · …`,
  выбрать leastPing и интервал 60 с. Выбрать балансировщик для подключения.
- Shadowrocket получает тот же TXT и `Eternal-Exit-WARP-Shadowrocket.conf`.
  Импортировать URI из буфера, применить конфигурацию и Global Routing → Config.

Группа содержит прямые XHTTP/TLS и gRPC/REALITY, до двух числовых адресов
Funnel и резерв по имени. Hysteria из старого набора в эту группу не входит,
поскольку её существующий выход использует адрес Eternal.
Проверка https://1.1.1.1/cdn-cgi/trace должна показывать `warp=on` и IP Cloudflare.

## Изоляция и обслуживание

- Новый VLESS-пользователь имеет маркер `eternal_exit` и отдельный UUID.
  В `/root/vpnbot/config/pac.json` поле `eternal_exit` хранит его UUID,
  UUID владельца и флаг `enabled`. Секрет WARP находится отдельно от бота.
- `restartXray()` всегда применяет `eternalExitRouting()` до перестройки
  gRPC. Выделенное правило пользователя стоит выше обычных правил.
  Если владелец/пользователь выключен или конфигурация неактивна, выход
  становится `blackhole`. Регенерация обычной маршрутизации не снимает защиту.
- Изолированный контейнер `eternal-egress` использует существующий образ
  `mercurykd/vpnbot-xr:1.5`, `/usr/bin/xray`, сеть `vpnbot_default`,
  IP `10.10.0.20`, SOCKS TCP+UDP 1080. Порты на хост не публикуются.
  У него `restart=unless-stopped`, read-only rootfs, все capabilities сняты.
- `/root/eternal-egress/account.json` содержит отдельную серверную регистрацию
  WARP. Ключи телефонов не используются. `egress-warp.py ACCOUNT OUTPUT`
  воспроизводит `/root/eternal-egress/config.json` с режимом файла 0600.
- В контейнере есть только WireGuard и blackhole; DNS через DoH 1.1.1.1
  тоже проходит WireGuard. Прямого выхода и резервного freedom нет.
  Для работы не нужны TUN, системные маршруты, новая машина или новый IP.
- Новые XHTTP URI явно задают `maxConnections=0`, `maxConcurrency="16-32"`,
  `cMaxReuseTimes=0`, `hMaxRequestTimes="600-900"`,
  `hMaxReusableSecs="180-300"`, `hKeepAlivePeriod=15`.
  Старые URI по умолчанию остаются с `maxConnections=3`.

Не удалять правило пользователя при отключении WARP. Для остановки нового
набора поставить `eternal_exit.enabled=false`, пересобрать Xray штатным
`restartXray`, затем остановить `eternal-egress`. Старые профили продолжат
работу. Возврат на старый набор на клиенте меняет внешний адрес на Eternal.

## Проверки

`php eternal/test-egress.php app/bot.php` проверяет изоляцию, блокировку при
отключении, повторную генерацию правил, новые URI и все три формата импорта.
Он также запускает прежние регрессии `test-direct443.php`.
Серверные изменения отдельно проверяются через Xray `run -test` и реальный
трафик, включая отказ контейнера WARP, UDP DNS и сохранность старого маршрута.
Мобильные приложения и LTE требуют отдельной проверки на устройствах.

Источники: [XMUX](https://github.com/XTLS/Xray-core/discussions/4113),
[WireGuard outbound Xray](https://xtls.github.io/en/config/outbounds/wireguard.html).
