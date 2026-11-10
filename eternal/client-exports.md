# Переносимые наборы Eternal

`EternalClientExports` выдаёт отдельные UTF-8 TXT-файлы для `v2rayn`, `karing_android` и `karing_ios`. `eternalClientExport(client, mode, resolve=true)` возвращает `name`, `content`, `mime` либо пустой массив. `sendEternalAdditionalFiles` отправляет результат только в личный чат инициатора и возвращает успех. Новых публичных ссылок подписки нет.

| Режим | v2rayN | Karing Android / iOS |
|---|---|---|
| `auto` | `Eternal-Auto-v2rayN.txt` | `Eternal-Auto-Karing.txt` |
| `exit` | `Eternal-Exit-WARP-v2rayN.txt` | `Eternal-Exit-WARP-Karing.txt` |

Одна строка — одна исходная URI. До `#` URI сохраняется побайтно: UUID/пароль, адрес, TLS/SNI, REALITY, transport, XHTTP `extra` и XMUX не преобразуются. Меняется только отображаемое имя. Права владельца и отдельного WARP-пользователя проверяют прежние `eternalAutoSources` / `eternalExitSources`. В `exit` нет Hysteria обычного владельца и запасного выхода напрямую. TXT не создаёт автоматическую группу и не заменяет настройки клиента.

## Проверка Karing на 10.11.2026

Официальное руководство допускает пакетные V2ray-конфигурации и локальный импорт файла; такой файл не обновляется по подписке. Поэтому выбран TXT, а не полный JSON, для которого клиент может самостоятельно перестроить DNS и маршрутизацию. [Руководство импорта](https://karing.app/en/app-manual/add-profiles), [начало работы](https://karing.app/en/quickstart).

Проверен интерфейс исходников `KaringX/karing`: HEAD `accbdfc05a999eaa3526aadb0aea994b3eafb7f5` и тег `v1.2.25.2802`. В обоих file picker принимает `.txt` для V2ray. Русские названия: «Добавить профиль» → «Импорт файла конфигурации» → «Выбрать файл», имя в «Примечание», сохранение ✓. Для первой проверки выбирается отдельный узел нужного набора и режим «Глобально»; общий автовыбор может включать узлы других профилей. [Импорт файла в стабильном исходнике](https://github.com/KaringX/karing/blob/v1.2.25.2802/lib/screens/add_profile_by_import_from_file_screen.dart), [локализация](https://github.com/KaringX/karing/blob/v1.2.25.2802/lib/i18n/ru.i18n.json).

У Karing есть Hysteria2, а разработчик отметил добавление XHTTP в `v1.2.9.1206`. В опубликованном ядре присутствуют XHTTP, packet-up, XMUX, gRPC и REALITY; схема XMUX содержит все поля наших наборов. Это проверено в ветках `karing_v1.13.18` (12.08.2026) и `karing_v1.14.4` (09.11.2026). [Официальный FAQ](https://karing.app/en/faq), [ответ разработчика по XHTTP](https://github.com/KaringX/karing/issues/1143), [схема транспорта](https://github.com/KaringX/sing-box/blob/karing_v1.14.4/option/v2ray_transport.go).

При этом `lib/app/utils/auto_conf_utils.dart`, на который ссылается импортёр, отсутствует в опубликованном дереве приложения, включая стабильный тег. Поэтому преобразование каждого URI-параметра внутри приложения не проверено по открытому коду. Старые отчёты об ошибках сложных XHTTP `extra` не считаются доказательством ошибки нашего набора; параметры без измерения не удаляются. [Вызов преобразователя](https://github.com/KaringX/karing/blob/v1.2.25.2802/lib/app/modules/server_manager.dart), [отчёт по расширенным параметрам](https://github.com/KaringX/karing/issues/1924).

На дату проверки GitHub stable — `1.2.26.2923`, App Store — `1.2.25.2802`. Установка: [Android — официальный GitHub](https://github.com/KaringX/karing/releases/latest), [iPhone/iPad — App Store](https://apps.apple.com/us/app/karing/id6472431552). Импорт и трафик на физическом Android/iOS в этом проходе не проверялись; совместимость опубликованного формата не равна проверке связи с телефона.

`test-client-exports.php` проверяет сохранность параметров, различие UUID режимов, отключённого/удалённого владельца, отключённый WARP-профиль, неверные аргументы, отсутствие DNS при `resolve=false`, имена файлов и личную доставку. Содержание файлов LxBox, Exclave и Shadowrocket сохраняется; отправщики теперь возвращают результат доставки Telegram.
