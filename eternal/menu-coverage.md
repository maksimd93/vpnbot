# Карта меню бота Eternal

Аудит 09.11.2026: в `app/bot.php` найдено **113 методов, формирующих кнопки или запрос ввода: 56 экранов и уведомлений, 57 запросов**. Таблицы перечисляют каждый найденный метод один раз. Обработчики файлов, QR и обычные ответы без кнопок учтены отдельно ниже. Это карта представления, а не утверждение о проверке каждого изменяющего действия на рабочем сервере.

Новый интерфейс включается только в режиме `simple`; `classic` сохраняет прежние меню. Главная, управление сервером и VLESS имеют отдельные построители. Остальные экраны проходят через `EternalFlowUi`: профили → WireGuard → списки → службы → прежний адаптер. Выбор раздела и просмотр не добавляют операций записи конфигурации. Исходные генераторы могут выполнять прежние проверки состояния.

## Экраны и уведомления — 56

| Раздел и модуль представления | Методы исходного бота | Организация |
|---|---|---|
| Главная — `eternal_ui.php` | `menu` | Статусы всех служб, устройство, помощь, управление, откат интерфейса. |
| VLESS — `eternal_xray_ui.php` | `xray` | Пользователи, подключения, контроль IP/HWID, статистика, шаблоны. |
| Настройки сервера — `eternal_admin_ui.php` | `configMenu` | Домен/порты, копии, обслуживание, отображение, администраторы, диагностика. |
| WireGuard / Amnezia — `eternal_wg_ui.php` | `statusWg`, `getClients`, `getClient`, `addWg`, `changeAllowedIps`, `subnet`, `confirmAwg3` | Два сервера сохраняют свой контекст. Устройства, файл/QR, доступ, сетевые параметры и протокол разделены. Подсети OpenConnect имеют возврат в его настройки. |
| Пользователи и шаблоны — `eternal_profiles_ui.php`, `eternal_legacy_ui.php` | `userXr`, `hwidUser`, `hwidLimit`, `templates`, `templateUser` | Карточка пользователя ведёт к подключению, файлам, доступу и шаблонам. HWID объясняется как контроль получения подписки. |
| Специальные подключения — `eternal_profiles_ui.php` | `eternalExitMenu`, `funnelMenu`, `cdnMenu`, `cdnShadowrocketMenu`, `warpMenu`, `warpAlternativeMenu`, `shadowrocketMenu` | Ссылки и инструкции сохранены; WARP перед Eternal отделён от серверного WARP-выхода. Возврат ведёт к способам подключения пользователя. |
| PAC и списки маршрутизации — `eternal_lists_ui.php` | `pacMenu`, `routes`, `listPac`, `xtlsblock`, `xtlswarp`, `xtlsproxy`, `xtlsapp`, `xtlsprocess`, `xtlssubnet`, `xtlsrulesset`, `deleteAll` | PAC разделён на ссылки, свои домены и источники. Импорт/экспорт/очистка находятся отдельно от записей. Кнопки описывают действие, а серверные правила отличены от клиентских. |
| Защита по IP — `eternal_lists_ui.php` | `ipMenu`, `analysisIp`, `searchLogs`, `denyList`, `autoAnalyzeLogs`, `frequencyAnalyze` | Блокировки, исключения, анализ и автоматизация разделены. Удаление журнала не выдается за разблокировку. Кнопка сведений показывается только для отдельного IPv4, который поддерживает обработчик. |
| Журналы — `eternal_lists_ui.php` | `logs` | Шесть файлов на странице; скачивание и очистка подписаны отдельно. Общая очистка и расписание вынесены в свой раздел. |
| Протоколы и DNS — `eternal_services_ui.php` | `mtproto`, `menuSS`, `dnstt`, `naiveMenu`, `hysteriaMenu`, `ocMenu`, `warp`, `adguardMenu` | Данные подключения сохранены. В OpenConnect отдельно подключение, пользователи и настройки; в DNS — адреса, доступ, резолверы и восстановление. |
| Обслуживание — `eternal_services_ui.php` | `checkVersion`, `mirrorMenu`, `menuLang`, `updatebot`, `ports`, `branches`, `showreset`, `config` | Назначение и последствия операций описаны; выбор ветки отделён от установки обновления, сброс WireGuard — от отката интерфейса. |

## Запросы ввода — 57

| Модуль представления | Методы исходного бота | Что поясняется |
|---|---|---|
| `eternal_profiles_ui.php` | `addXrUser`, `renameXrUser`, `timerXr`, `templateAdd`, `templateCopy` | Формат имени/UUID, срок, включение пользователя при назначении срока, JSON шаблона и имя копии. |
| `eternal_wg_ui.php` | `rename`, `timer`, `addips`, `defaultDNS`, `defaultMTU`, `changeMTU`, `subnetAdd`, `calc`, `changeIps` | Выбранный сервер, формат DNS/MTU/CIDR, срок, расчёт исключений и необходимость повторного импорта профиля. `addips` вызывается кнопкой `/add_ips`. |
| `eternal_lists_ui.php` | `checkurl`, `importList`, `include`, `reverse`, `subzones`, `exclude`, `autoScanTimeout`, `autoCleanLogs` | URL проверяет PAC, а не связность; CSV заменяет весь список; описаны форматы правил, время и отключение автоочистки. |
| `eternal_services_ui.php` | `setSecret`, `addOcUser`, `addOverrideHtml`, `import`, `domain`, `selfssl`, `adguardpsswd`, `setAdguardKey`, `enterAdmin`, `addSubdomain`, `enterPage`, `checkdns`, `addupstream`, `dnsttDomain`, `dnsttPassword`, `warpPlus`, `backup`, `changeTGDomain` | Формат данных, область действия и необходимые действия в клиенте. `enterAdmin` вызывается `/addadmin`. |
| `eternal_legacy_ui.php` | `sspswd`, `changeCamouflage`, `changeOcDomain`, `changeOcDns`, `changeOcPass`, `changeNaiveUser`, `changeNaiveSubdomain`, `changeNaivePass`, `changeHysteriaPass`, `setIpLimit`, `changePort`, `addLinkDomain`, `setHwidDevices`, `mainOutbound`, `setHwidUserLimit`, `changeFakeDomain`, `grpcRealityAskDomain` | Общие пароли, адреса и маскировка, порты, уведомления по IP, контроль подписки. `grpcRealityAskDomain` вызывается `/grpcRealityDomain`. |

## Дополнительные результаты и действия

- `searchIp`, `searchSuspiciousIp`, `urlcheck` получают пояснение и навигацию через `uiListsMessage`. Исходные строки журналов и URL сохраняются. Совпадение `match` переводится только в контексте проверки PAC.
- `getLog`, `exportList`, выгрузки конфигураций и QR сохраняют исходные файлы/полезную нагрузку; назначение объяснено в исходном меню. Файлы не преобразуются в резервные копии другого клиента.
- `deleteAll` сохраняет исходное подтверждение `/deleteYes`. Существенные операции доступа, серверных правил, очистки и перезапуска используют проверку последствий в `eternal_admin_ui.php` / `eternal_flow_ui.php`. Кнопки подтверждения не заменяют обработчики самой операции.
- `reverse`, `subzones`, `exclude`, `addSubdomain`, `addOverrideHtml` сохраняются для старых команд, даже если их нет в текущих основных меню.

## Проверка границ адаптеров

`routes` определяется наличием одновременно `/xtlsblock` и `/xtlswarp`. Одной кнопки `/xtlsblock` недостаточно: она также бывает возвратом из подтверждения очистки. Страницы списка определяются по `/include TYPE` или `/deleteYes TYPE`, защита IP — по собственным callback. Поэтому экран подтверждения не превращается в корневое меню.

`test-lists-ui.php` проверяет сохранность целей операций и ссылок, отдельные списки/инструменты, состояние переключателей, индексы файлов при пагинации, отсутствие записей конфигурации, возврат старого интерфейса и сохранность диагностических строк. Остальные модули проверяются `test-ui.php`, `test-admin-ui.php`, `test-wg-ui.php`, `test-services-ui.php` и общей проверкой рендеринга. Проверки используют подменённую отправку Telegram и не применяют реальные изменения VPN.

Известное прежнее ограничение: `deleteYes` для `blocklist`/`warplist` очищает сохранённый список, но сам не вызывает пересборку Xray. Экран не обещает немедленного применения серверных правил. Это поведение обработчика не изменено в рамках переработки представления.
