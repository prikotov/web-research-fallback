# Маршруты и кейсы (сеть владельца, проверено 2026-11-13…24)

| Цель | Direct | Прокси | Wayback | Примечание |
|---|---|---|---|---|
| youtube.com | connect timeout | 200 | — | прокси обязателен |
| archive.org API (metadata/available/CDX) | 200 | — | — | снапшоты web.archive.org могут 429 → прокси |
| api.github.com, suggestqueries.google.com | 200 | — | — | |
| cbr.ru, moex.com | 200 | — | — | см. cbr-core / moex-site-core |
| eutils.ncbi.nlm.nih.gov | 200 | — | — | |
| lite.duckduckgo.com/lite/ POST q= | 200 | 200 | — | html.duckduckgo.com/html/ GET — пусто |
| finam.ru | 403 (research UA) / **200 (browser UA)** | 403 (research UA) | 200 | фильтр по UA, не гео; Wayback+прокси (cp1251) — запасной маршрут |
| singjupost.com | timeout | read timeout/502 | 200 (2022-12-09, через прокси) | пример полной цепочки |
| scribd.com | JS-challenge | JS-challenge | пробовать | не вышло — «не проверено» |
| archive.ph / archive.today | 000 | 000 | — | из РФ недоступен оба маршрута (2026-11-25); в цепочку не добавлен |
| sberbank.ru / gosuslugi.ru / digital.gov.ru | 200 на машине с российскими CA в trust; errno 60 без них | не лечит (доверие локальное) | 200 | сертификаты НУЦ Минцифры; шаг direct-ruca с бандлом скилла |

## Кейс: Reuters 29.07.2021 о нерезидентах на Мосбирже

Оригинал недоступен; перепечатка Finam — 403/JS-challenge и direct, и proxy.
Маршрут: Wayback CDX по URL finam → снапшот 20220906052419 → загрузка через
прокси → декодировать cp1251. Содержимое проверено, маршрут и дата снапшота
зафиксированы в карточке источника (ai-trading-sources,
sources/2026-11-24-reuters-moex-nonresidents-1h2021.md).

## Поисковые движки (проверено 2026-11-25, запрос «доля физических лиц объем торгов акциями»)

| Движок | Результат | Пригодность |
|---|---|---|
| lite.duckduckgo.com/lite/ POST q= | 200, релевантно (cbr/moex/finam) | да, движок ddg |
| bing.com/search с mkt=ru-RU&setlang=ru | 200, релевантно, серверный HTML | да, движок bing |
| bing.com/search без mkt | 200, но словари «доля» | нет: рынок по умолчанию en |
| google.com/search | 200, страница «enablejs» | нет: результаты только через JS |
| ya.ru / yandex.ru/search | SmartCaptcha | нет: captcha боту, обход запрещён |
| go.mail.ru/search | 200, JS-оболочка 1.8 КБ | нет: результатов в HTML нет |

## Feed-экспорты (проверено 2026-11-25; карта — WrfClient::FEEDS, опыт news-core)

| Лента | Статус | Примечание |
|---|---|---|
| rssexport.rbc.ru/rbcnews/news/30/full.rss | 200 | полные тексты статей; шаг feed берёт XML item |
| tass.ru/rss/v2.xml | 200 | |
| ria.ru/export/rss2/archive/index.xml | 200 | |
| interfax.ru/rss.asp | 301 → 200 | редирект следует сам |
| kommersant.ru/RSS/news.xml | 301 → 200 | редирект следует сам |
| 1prime.ru/export/rss2/archive/index.xml | из news-core | сегодня не перепроверялась |

HTML этих сайтов с браузерным UA и заголовками из РФ — 200 (отказы ситуативны:
datacenter-IP, настроение WAF); feed-маршрут — запасной путь и источник полных текстов.

## Диагностика кодов

- 000 / connect timeout — сеть или блокировка маршрута; пробовать прокси.
- read timeout — сервер принял соединение и молчит; вариации UA/схемы, прокси.
- 403 — блокировка/гео/WAF; прокси (exit IP), затем Wayback.
- 429 — rate limit: пауза; прокси меняет IP; Wayback для архивных копий.
- 502 через прокси — прокси не достучался до origin; http-вариант, другой маршрут.
- JS-challenge — тело содержит «Just a moment»/«Client Challenge»; fetch.php
  считает такой 200 неуспехом и продолжает цепочку.
- Сетевые errno curl 6 (DNS), 7 (connect), 28 (timeout) — признак блокировки
  маршрута, а не сайта: fetch.php пропускает вариации и идёт сразу в прокси.
  HTTP-отказы (403/429/5xx) — наоборот, сначала браузерный UA и смена схемы.
- TLS errno 60/35/77/80… — недоверие к сертификату (российские сайты на CA
  Минцифры): шаг direct-ruca с `references/russian-trusted-ca.pem`; прокси
  бесполезен, проверка локальная. Эмуляция: `--ca` с foreign-only бандлом.