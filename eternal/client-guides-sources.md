# Источники инструкций подключения

Проверено 10.11.2026. `EternalGuidesUi::uiClientGuide()` создаёт самостоятельное сообщение: получателю нужны перечисленные файлы и приложение, доступ к боту и его кнопкам не нужен. Сообщение отправляется отдельно от документов; пересылать нужно весь комплект. Имена файлов и узлов берутся из экспортов этого репозитория. Инструкция не содержит ключей; ожидаемый IP обычного выхода подставляется из текущего IPv4 сервера.

Установка, импорт и названия действий сверены с первичными материалами ниже. Это проверка документации и исходного кода, а не результат запуска всех клиентов на реальных телефонах. Точные названия и расположение пунктов могут измениться после обновлений; ссылки на релизы ведут на актуальную версию.

## LxBox · Android

- [README разработчика](https://github.com/Leadaxe/LxBox): официальные Google Play и GitHub Releases.
- [Руководство](https://github.com/Leadaxe/LxBox/blob/main/docs/USER_GUIDE.md): App Settings → General → Backup & restore, импорт JSON, Merge with existing, перезапуск после применения.
- [Формат резервной копии](https://github.com/Leadaxe/LxBox/blob/main/docs/spec/features/017-BACKUP_AND_STORAGE/FEATURE.md): `app: lxbox`, `kind: backup`; полный бэкап отличается от переноса на Desktop.
- [Русские названия](https://github.com/Leadaxe/LxBox/blob/main/app/assets/l10n/ru/ui.json), [применение импорта](https://github.com/Leadaxe/LxBox/blob/main/app/lib/screens/backup_screen/restore_flow.dart). Локальный исходник, использованный для проверки: `36b67fc2b1cb31171cda5faa09ad64a66dd8fb93`.

Экспорты: `Eternal-Auto-Android.json` / `Eternal-Exit-WARP-Android.json`. Уже содержат папку и запись `kind: auto`; ручное добавление авто-узла описано только как восстановление при его отсутствии. Для прежнего интерфейса сохранена подсказка о прямом пункте «Резервное копирование» в боковом меню.

## Exclave · Android

- [README разработчика](https://github.com/ExclaveNetwork/Exclave) и [релизы](https://github.com/ExclaveNetwork/Exclave/releases/latest). На дату проверки 0.17.67: обычный ARM64 APK имеет суффикс `arm64-v8a.apk`, версия `legacy` предназначена для старых Android.
- [Русские строки интерфейса](https://github.com/ExclaveNetwork/Exclave/blob/dev/app/src/main/res/values-ru/strings.xml): импорт файла/буфера, ручные настройки, балансировщик, стратегия, URL и интервал проверки, VPN-сервис.
- [Типы и стратегии балансировщика](https://github.com/ExclaveNetwork/Exclave/blob/dev/app/src/main/res/values/arrays.xml): список, `leastPing`.
- [Редактор балансировщика](https://github.com/ExclaveNetwork/Exclave/blob/dev/app/src/main/java/io/nekohasekai/sagernet/ui/profile/BalancerSettingsActivity.kt): ручное добавление участников, отдельные URL и интервал.

Экспорты: `Eternal-Nodes.txt` / `Eternal-Exit-WARP-Nodes.txt`. Это URI-списки; автоматический выбор создаётся отдельным балансировщиком внутри Exclave. В него включаются только узлы соответствующего файла.

## Shadowrocket · iOS / iPadOS

- [App Store разработчика](https://apps.apple.com/app/shadowrocket/id932747118): идентификатор 932747118, платное приложение, поддержка импорта конфигурации из iCloud.
- [Сайт разработчика из карточки App Store](https://shadowlaunch.com/): официальная идентичность и контакт.
- [Исходное руководство LOWERTOP](https://github.com/LOWERTOP/Shadowrocket/wiki): самостоятельное руководство, составленное на основе списка команд официальной группы и изменений приложения. Подтверждает импорт URI из буфера и кнопку Paste, Config → Import from Cloud → Use Configuration, режим Config, системное разрешение VPN. Это не исходный код приложения и не объявлено нами официальным сайтом разработчика.

Экспорт состоит из двух файлов: общий TXT узлов и `Eternal-Auto-Shadowrocket.conf` либо `Eternal-Exit-WARP-Shadowrocket.conf`. В конфигурации находятся `url-test` и `FINAL` со ссылками на имена узлов; поэтому оба файла обязательны, имена узлов не следует менять. Советы об установке HTTPS-сертификата не нужны и не даются.

## v2rayN · Windows

- [Официальные релизы](https://github.com/2dust/v2rayN/releases/latest), [описание архивов](https://github.com/2dust/v2rayN/wiki/Release-files-introduction): ZIP для своей платформы нужно распаковать целиком. Основной сценарий описан для Windows 10/11 Intel/AMD, `v2rayN-windows-64.zip`.
- [Руководство интерфейса](https://github.com/2dust/v2rayN/wiki/Description-of-some-ui): импорт обычных или base64 URI-списков, ручной выбор профиля, системный прокси.
- [Русская локализация 7.25.6](https://github.com/2dust/v2rayN/blob/7.25.6/v2rayN/ServiceLib/Resx/ResUI.ru.resx): точные подписи импорта, активного сервера, системного прокси, TUN и перезапуска с правами администратора.
- [Главный список профилей 7.25.6](https://github.com/2dust/v2rayN/blob/7.25.6/v2rayN/v2rayN/Views/ProfilesView.xaml): Enter делает выделенный узел активным.
- [Встроенная маршрутизация 7.25.6](https://github.com/2dust/v2rayN/blob/7.25.6/v2rayN/ServiceLib/Handler/ConfigHandler.cs) и [правила Global](https://github.com/2dust/v2rayN/blob/7.25.6/v2rayN/ServiceLib/Sample/custom_routing_global): новая установка создаёт `V4-全局(Global)`. Имя не локализовано, старые установки могут сохранять прежний префикс, поэтому инструкция предлагает выбрать правило по `(Global)`.

Экспорты: `Eternal-Auto-v2rayN.txt` / `Eternal-Exit-WARP-v2rayN.txt`. `Auto` здесь обозначает семейство серверных настроек; TXT не создаёт автоматическую группу клиента. Не предлагается генерация группы из всех профилей, которая захватила бы чужие узлы. Обычный сценарий использует системный прокси, TUN описан отдельно для остальных приложений.

## Karing · Android / iOS / iPadOS

- [Официальная страница загрузки](https://karing.app/en/download), [GitHub Releases](https://github.com/KaringX/karing/releases/latest), [App Store](https://apps.apple.com/app/karing/id6472431552). На дату проверки Android stable 1.2.26.2923, App Store 1.2.25.2802. ARM64 APK имеет часть имени `android_arm64-v8a`.
- [Экран импорта файла](https://github.com/KaringX/karing/blob/accbdfc05a999eaa3526aadb0aea994b3eafb7f5/lib/screens/add_profile_by_import_from_file_screen.dart) и [меню добавления](https://github.com/KaringX/karing/blob/accbdfc05a999eaa3526aadb0aea994b3eafb7f5/lib/screens/group_helper.dart): импорт файла/буфера, имя профиля («Примечание»), сохранение ✓ сверены с исходниками. Выбор отдельного сервера и переключатель подключения дополнительно проверены по документации.
- [Русские строки](https://github.com/KaringX/karing/blob/accbdfc05a999eaa3526aadb0aea994b3eafb7f5/lib/i18n/ru.i18n.json) `meta.rule`, `meta.global`, `meta.outboundMode` дополнительно сверены на стабильной версии `v1.2.25.2802`: «Правила», «Глобально», «Исходящий режим».

Экспорты для обеих платформ: `Eternal-Auto-Karing.txt` / `Eternal-Exit-WARP-Karing.txt`. Это URI-списки, без авто-группы. Для первого запуска выбирается конкретный резервный узел своего профиля и режим «Глобально». Глобальный автоматический выбор не предлагается, поскольку он может выбрать узел другого профиля и изменить выход. Пользовательский ограниченный автовыбор требует отдельной настройки и не включён в основной сценарий.

## Общая проверка

Проверка выполняется запросом браузера к `https://1.1.1.1/cdn-cgi/trace`: IPv4 позволяет сравнить выход с известным IPv4 Eternal. Для серверного WARP проверяется `warp=on`. Наличие строки «Подключено» само по себе не считается успешным сетевым тестом. Wi-Fi и LTE проверяются отдельно; скорость и доступность конкретного оператора не гарантируются инструкцией.
