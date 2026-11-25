# Web Research Fallback

> Переносимый скилл и скрипты доступа к недоступным веб-источникам:
> диагностика → вариации URL/UA → прокси → Wayback Machine; поиск через
> lite.duckduckgo. Для любого проекта и агента.

Структура — как у `cbr-core` / `google-analytics-core`: корневой `SKILL.md`
(навык: как пользоваться), скрипты в `scripts/`, справка маршрутов в
`references/`. PHP 8.4+, ext-curl, без composer-зависимостей — как
`Ga4Client.php` в google-analytics-core.

Общий клиент — `WrfClient.php` в корне: `loadProxy` (--proxy → env → конфиг),
`ensureConfigIgnored` (проверка/пополнение .gitignore репо, где лежит конфиг;
приём из `Ga4Client.php`), `fetch` через ext-curl (прокси любой схемы),
`isChallenge`, `urlVariants`, `waybackSnapshot`, `ddgLite`, `argv`/`emit`.
Скрипты `scripts/fetch.php` и `scripts/search.php` — тонкие CLI над клиентом.

## Установка

```bash
git clone https://github.com/prikotov/web-research-fallback.git .agents/skills/web-research-fallback
# или симлинк на существующий клон (в любом проекте):
ln -s /home/dp/MyProjects/web-research-fallback .agents/skills/web-research-fallback
# общий каталог скиллов нескольких проектов:
ln -s /home/dp/MyProjects/web-research-fallback /home/dp/MyProjects/.ai_sync/.agents/skills/web-research-fallback
```

## Настройка прокси (собственный конфиг скилла)

```bash
cp .wrf_config.example.json .wrf_config.json   # в корне репо
chmod 600 .wrf_config.json                    # затем заполнить поле "proxy"
```

`.wrf_config.json` — в `.gitignore` этого репо. Порядок разрешения:
`--proxy` → env `WEB_RESEARCH_PROXY` → `.wrf_config.json` (или путь из `WRF_CONFIG`).
Env-файлы проектов (`.env.dev.local`, `.env`…) скилл не читает.
В коммитах секретов нет и быть не должно.

При каждом запуске `fetch.php`/`search.php` проверяют, что конфиг закрыт
`.gitignore` того репо/каталога, где он лежит (включая случай, когда скилл
скопирован внутрь чужого репо): не закрыт — запись добавляется автоматически,
в stderr — предупреждение. Приём из `Ga4Client.php` (google-analytics-core).

## Использование

```bash
php scripts/fetch.php https://example.com/article --out /tmp/a.html
# {"ok": true, "route": "proxy", "status": 200, "bytes": …, "attempts": […]}
php scripts/search.php "moex retail investors share 2021" --limit 8
```

`fetch.php` сам проходит цепочку и печатает JSON-отчёт с маршрутами попыток
(без секретов); тело сохраняет в `--out` или `/tmp/wrf_<hash>.html`.
`search.php` — JSON-список результатов ddg lite.

## Как устроена цепочка доступа

Порядок маршрутов `fetch.php` (агенту знать его не нужно — скрипт проходит
цепочку сам и возвращает отчёт с `attempts`; раздел для понимания и отладки):

1. **direct.** Сетевая ошибка (DNS/connect/timeout — типично для исходящих
   блокировок из РФ) → вариации пропускаем, сразу прокси: тот же маршрут и IP,
   вариации UA/схемы там бессмысленны и только жгут время.
2. **HTTP-отказ (403/429/5xx/JS-challenge)** → direct с браузерным UA
   (WAF фильтрует по UA), затем смена схемы http↔https (legacy-сайты без TLS).
   www-вариант не нужен: редиректы www скрипт следует сам.
3. **TLS-отказ (errno 60/35/77…)** — российские сайты на сертификатах НУЦ
   Минцифры, которых нет в дефолтном trust store: direct с бандлом российских
   CA (`references/russian-trusted-ca.pem`, шаг `direct-ruca`). Прокси не лечит:
   доверие к сертификату проверяется локально. Не вышло → Wayback.
4. **proxy** (браузерный UA) — меняет exit IP: гео-блоки и 429 по IP.
   HTTP-отказ через прокси → смена схемы через прокси.
5. **Wayback**, если мёртв на обоих маршрутах: `archive.org/wayback/available`
   (обычно direct) → снапшот direct → при 429 снапшот через прокси.
   Пагинация и `?singlepage=1` — внутри снапшота.
6. **archive.today в цепочке нет**: из РФ недоступен и direct, и через прокси
   (проверено 2026-11-25, оба 000).

## Российские CA (сертификаты НУЦ Минцифры)

Российские сайты (sberbank.ru, gosuslugi.ru, …) перешли на сертификаты
российского УЦ; дефолтный trust store их не знает → curl errno 60.
В скилле лежит публичный бандл `references/russian-trusted-ca.pem`
(корневой + промежуточный CA): шаг цепочки `direct-ruca` использует его
через CURLOPT_CAINFO. Прокси TLS-недоверие не лечит (проверка локальная).

Происхождение бандла (2026-11-25): зеркала github.com/AKotov-dev/russiancrt
и github.com/umatrix81/certsru; SHA256 корневого сертификата сверен между
зеркалами: `D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31`.
Официальные инструкции по установке в систему: https://www.sberbank.ru/ru/certificates.
Проверка сценария «чистая машина»: `php scripts/fetch.php URL --ca foreign-only.pem`
(direct даст errno 60, direct-ruca — 200).

## Правила

- Не выводить прокси/токены в stdout, логи, файлы, отчёты; никаких `curl -v` с прокси.
- Не обходить подписки/авторизацию; Wayback — публичный архив.
- Rate limit уважать; временные файлы — в `/tmp`.
- «Не проверено» фиксировать явно; сниппет поисковика не выдавать за факт.

Подробнее — [SKILL.md](SKILL.md), маршруты и кейсы — [references/routes.md](references/routes.md).