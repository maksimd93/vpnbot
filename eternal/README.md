# Правки для сервера Eternal

Этот форк — [mercurykd/vpnbot](https://github.com/mercurykd/vpnbot) плюс несколько правок. Всё остальное работает как в оригинале.

## Что изменено

| Что | Где | Зачем |
|---|---|---|
| Hysteria 2.6.5 → 2.13.0 | `dockerfile/hysteria.dockerfile`, образ `vpnbot-eternal/hysteria` | Stateless reset: клиент сразу узнаёт, что сессия умерла, и переподключается, а не ждёт 30 секунд. Сертификат подхватывается с диска без перезапуска. |
| AmneziaWG → amneziawg-go v3.1.20260828, tools v3.1.20260812 | `dockerfile/wireguard.dockerfile`, образ `vpnbot-eternal/wg` | Последние релизы вместо master на момент сборки. Параметры AWG 2.0 работают как раньше, новые функции AWG 3 выключены, пока их не задать. |
| Перезапуск Naive и ocserv после выпуска сертификата | `app/bot.php`, `setSSL()` | Caddy и ocserv держат старый сертификат в памяти, после перевыпуска или смены поддомена клиенты получали несовпадающий сертификат. |
| `certbot --cert-name <домен>` | `app/bot.php`, `setSSL()` | При смене поддомена certbot создавал новую цепочку (`-0001`, `-0002`), а бот брал сертификат из старой. Из-за этого Naive на новом поддомене перестал работать. |
| Обновление из двух источников | `update/update.sh` | Бот тянет этот форк, затем вливает обновления mercurykd. |
| Буферы UDP 16 МБ и port hopping для Hysteria | `eternal/` | С буфером 208 КБ сокет Hysteria терял пакеты. Port hopping заменяет «замороженный» провайдером UDP-поток новым без обрыва сессии. |

## Как приходят обновления

- `origin` — этот форк, `upstream` — mercurykd/vpnbot.
- Бот, как и раньше, следит за версиями mercurykd и при включённом autoupdate обновляется сам.
- При обновлении: сначала `git pull` из форка, затем `git merge upstream/<ветка>`.
- Если обновление mercurykd конфликтует с правками, оно пропускается целиком: бот запускается на текущей версии, а в сообщении после обновления будет строка `conflicts with local patches`. Тогда upstream нужно смержить в форк вручную и обновить бота ещё раз.
- Переключить ветку из бота можно только на ветку, которая есть в форке; правки лежат в `master`.

## Port hopping

Сервер: UDP 20000–20999 перенаправлены прямо на контейнер hysteria (`eternal/porthop.sh`, юнит `hysteria-porthop.service`). Порт 44333 работает как раньше, старые конфиги не ломаются.

Клиент (sing-box, LxBox — импорт JSON):

```json
{
  "type": "hysteria2",
  "tag": "Eternal HY2",
  "server": "146-103-110-71.nip.io",
  "server_ports": ["20000:20999"],
  "hop_interval": "10s",
  "hop_interval_max": "20s",
  "password": "<пароль из меню Hysteria в боте>",
  "tls": { "enabled": true, "server_name": "146-103-110-71.nip.io" }
}
```

Интервал прыжков держите меньше 30 секунд — это таймаут простоя QUIC.

## Установка и откат

```bash
cd /root/vpnbot
bash eternal/install.sh     # буферы UDP + port hopping, можно запускать повторно
bash eternal/uninstall.sh   # вернуть как было
```

Откатить одну из правок в коде: `git revert <коммит>` в форке, затем «update bot» в боте. Образы до обновления сохранены на сервере как `rollback/vpnbot-hysteria:1.0` и `rollback/vpnbot-wg:1.2`, а также лежат на Docker Hub как `mercurykd/vpnbot-hysteria:1.0` и `mercurykd/vpnbot-wg:1.2`.
