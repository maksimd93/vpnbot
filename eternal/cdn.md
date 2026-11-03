# Eternal · Turbo

Единый профиль LxBox: **MASQUE H3 Cloudflare → CDN → VLESS XHTTP → Eternal**.
MASQUE скрывает первый участок к CDN. Прямые CDN-варианты VLESS/SS-WS этой
функцией не выдаются. Финальный внешний IP и устойчивость проверяются через
полную цепочку; короткий успешный TLS handshake недостаточен.

В боте: **VLESS → пользователь → Быстрый CDN**. Та же кнопка есть в меню
резерва и WARP. Копировать JSON целиком → LxBox → + → импорт из буфера →
выбрать **Eternal · Turbo**, переподключиться. Профиль содержит отдельную
регистрацию MASQUE для одного устройства. Не использовать её одновременно
на телефоне и тестовом ПК. Для Shadowrocket предусмотрены два отдельных шага
с собственной регистрацией MASQUE; на iPad реальная работа ещё проверяется.

## Приватная конфигурация выдачи

`pac.json.eternal_cdn` содержит:

```json
{
  "enabled": true,
  "uuid": "<активный UUID владельца>",
  "host": "<домен CDN-туннеля>",
  "server": "188.114.97.1",
  "port": 443,
  "live_path": "<текущий XHTTP path>",
  "profile": {"outbounds": ["<MASQUE outbound>", "<VLESS outbound>"]}
}
```

Это схема полей, не готовый клиентский конфиг. В рабочем профиле outbounds
содержит два объекта, полностью проверенных ядром. MASQUE: server162.159.198.2,
port443, profilecloudflare, vhttph3, MTU1280, IP и P256 private/public key,
TLS SNIwww.cloudflare.com. VLESS: существующий UUID владельца,
server188.114.97.1/443, TLS с проверкой домена CDN, ALPNh2, XHTTP packet-up,
host/path действующего входа, detour на тег MASQUE. Имя VLESS: Eternal · Turbo.
В итоговом профиле только outbounds; привязки к интерфейсу/адресам ПК удаляются.
Секретные ключи не помещать в git, диагностику stdout или эту документацию.

Экспорт проверяет активного владельца, enabled, PAC и actual network xhttp,
совпадение live_path, UUID обоих серверных представлений, hostname/server/port,
TLS verification, связь detour и ровно два ожидаемых outbound. Максимум JSON3000байт.
При disable, смене UUID/пути или некорректном профиле ключ не выдаётся.

## Проверки и откат

Наблюдаемая особенность холодного старта: Cloudflare иногда отклоняет первый
MASQUE CONNECT-IP с `PROTOCOL_VIOLATION (remote)`, а следующий запрос проходит.
Это описано в [SPEC121 ядра](https://github.com/Leadaxe/sing-box-lx/blob/lx/SPECS/TASKS/121-MASQUE_AUTO_MEMORY_TTL/SPEC.md).
Задержка после старта сама по себе не прогревает ленивую сессию. В LxBox при
первой ошибке повторить реальную проверку задержки и запрос; не выдавать это
за исправленный в ядре дефект или гарантированно успешный первый dial.

На Eternal постоянный коннектор `eternal-cdn-vpn.service`, конфиги/credentials
в `/etc/eternal-cdn/`. Origin — локальный небуферизующий nginx19882, далее
основной Xray. Дополнительный nginx server находится в `config/override.conf`
между маркерами `ETERNAL_CDN_STREAM`; основной Xray не перезапускался.
Ресурсы CDN не зависят от срока жизни тестовой службы.

Offline: `php eternal/test-cdn.php app/bot.php`, затем прежний
`eternal/test-funnel.php`. Только синтетические ключи/конфиги; Telegram и
Cloudflare API не вызываются. Проверяется полное копирование JSON после HTML,
включая ключи, TLS и detour; недоступность другому/отключённому пользователю,
защита от stale path/server/hostname, лишних outbound и Windows bindings.

На телефоне/LTE нужен отдельный реальный тест. Измерение на ПК не является
подтверждением Android или Shadowrocket. Перед передачей ключа пользователю
остановить использующие его тестовые PC-клиенты.

Откат выдачи: `eternal_cdn.enabled=false`. Это скрывает меню, но не отзывает
уже скопированный MASQUE-ключ; отзыв выполняется отдельно для его регистрации.
Управление туннелем CDN — отдельная задача эксплуатации.

## Shadowrocket

Опциональная запись `eternal_cdn.shadowrocket` содержит `uuid`, `masque_uri`,
`vless_uri`. URI MASQUE строится по [исходному генератору byJoey](https://github.com/byJoey/warp-masque-actions/blob/main/scripts/gen_masque.py):
`masque://162.159.198.2:443?publicKey=...&privateKey=...&ip=...&dns=...&udp=1#MASQUE-SR`.
Нужна отдельная регистрация, не ключ Android. URI-поля SNI и HTTP-version
не подтверждены, поэтому шаг1 требует вручную SNIwww.cloudflare.com,
HTTP/3(H3), MTU1280. Это обязательная часть настройки после импорта.

Шаг2 выдаёт VLESS TLS XHTTP на CDN с тем же владельцем и live_path, имя
Eternal-Turbo-SR. Его Proxy Pass / Через прокси (в некоторых переводах
«Пароль прокси») направляется через MASQUE-SR. Подключаться к Eternal-Turbo-SR;
для первого теста глобальная маршрутизация Прокси.

Кнопка Shadowrocket скрыта без корректной отдельной записи. Весь child-экспорт
зависит от валидного Android parent, UUID, URI scheme/host/port/TLS/SNI/path;
URI MASQUE ограничен2000байт, VLESS1000байт. Ключи двух устройств сравниваются,
чтобы не выдать Android-регистрацию повторно. Проверка ядром на ПК не считается
подтверждением Shadowrocket. Для UI шагов есть синтетические offline-тесты.
