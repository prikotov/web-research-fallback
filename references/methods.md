# Способы и кейсы (сеть владельца, проверено 2026-11-13…24)

| Цель | Direct | Прокси | Wayback | Примечание |
|---|---|---|---|---|
| youtube.com | connect timeout | 200 | — | прокси обязателен |
| archive.org API (metadata/available/CDX) | 200 | — | — | снапшоты web.archive.org могут 429 → прокси |
| api.github.com, suggestqueries.google.com | 200 | — | — | |
| cbr.ru, moex.com | 200 | — | — | см. cbr-core / moex-site-core |
| eutils.ncbi.nlm.nih.gov | 200 | — | — | |
| lite.duckduckgo.com/lite/ POST q= | 200 | 200 | — | html.duckduckgo.com/html/ GET — пусто |
| finam.ru | 403 (research UA) / **200 (browser UA)** | 403 (research UA) | 200 | фильтр по UA, не гео; Wayback+прокси (cp1251) — запасной способ |
| singjupost.com | timeout | read timeout/502 | 200 (2022-12-09, через прокси) | пример полного перебора способов |
| scribd.com | JS-challenge | JS-challenge | пробовать | не вышло — «не проверено» |
| archive.ph / archive.today | 000 | 000 | — | из РФ недоступен оба способа (2026-11-25); в перебор не добавлен |
| sberbank.ru / gosuslugi.ru / digital.gov.ru | 200 на машине с российскими CA в trust; errno 60 без них | не лечит (доверие локальное) | 200 | сертификаты НУЦ Минцифры; hint отчёта ведёт на официальную установку (gosuslugi.ru/crt) |

## Кейс: Reuters 29.07.2021 о нерезидентах на Мосбирже

Оригинал недоступен; перепечатка Finam — 403/JS-challenge и direct, и proxy.
Способ: Wayback CDX по URL finam → снапшот 20220906052419 → загрузка через
прокси → декодировать cp1251. Содержимое проверено, способ и дата снапшота
зафиксированы в карточке источника (ai-trading-sources,
sources/2026-11-24-reuters-moex-nonresidents-1h2021.md).

## Поисковые движки (проверено 2026-11-25, запрос «доля физических лиц объем торгов акциями»)

| Движок | Результат | Пригодность |
|---|---|---|
| lite.duckduckgo.com/lite/ POST q= | 200, релевантно (cbr/moex/finam) | да, движок ddg |
| bing.com/search с mkt=ru-RU&setlang=ru | 200, релевантно, серверный HTML | да, движок bing |
| bing.com/search без mkt | 200, но словари «доля» | нет: рынок по умолчанию en |
| google.com/search | 200, страница «enablejs»; Chrome рендерит результаты, но реальные URL в шифрованных /goto-токенах (gbv=1 не помогает) | нет: URL источников не извлечь |
| ya.ru / yandex.ru/search | SmartCaptcha даже через headless Chrome | нет: captcha боту, обход запрещён |
| go.mail.ru/search | 200, JS-оболочка 1.8 КБ; через Chrome — оболочка авторизации | нет: результатов нет |

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
datacenter-IP, настроение WAF); feed-способ — запасной путь и источник полных текстов.

## JS-challenge и headless Chrome (проверено 2026-11-25)

| Сайт | curl/browser-UA | headless Chrome | Примечание |
|---|---|---|---|
| tass.ru/search | 200, оболочка servicepipe (js-challenge-loader, 1.8–2.4 КБ) | 200, ~590 КБ с результатами | UA chrome = Linux/146 (как в news-web); Win/126 → снова оболочка |
| google.com/search | 200, страница enablejs | 200, 1.4–2.7 МБ DOM с результатами, но URL в /goto-токенах | не пригоден для поиска: ссылки не извлечь |
| ya.ru / yandex.ru/search | SmartCaptcha | SmartCaptcha («Вы не робот?») | эмуляция не помогает |
| go.mail.ru/search | JS-оболочка | оболочка авторизации | эмуляция не помогает |

Вывод: эмуляция Chrome открывает контент-сайты (TASS), но не поисковики:
google прячет URL, yandex требует captcha, mail — авторизацию.

Chrome-способ включается автоматически при пометках challenge/js-shell в attempts;
`--no-chrome` отключает. Авторизованный прокси chrome не поддерживает.

## Внутри-сайтовый поиск новостей (шаблоны из skills/news-web t-invest-agent)

Когда глобальные движки шумят, поиск самих сайтов отдаёт HTML без JS:

| Сайт | Шаблон |
|---|---|
| Kommersant | `https://www.kommersant.ru/Search/Results?search_query={query}` |
| RIA | `https://ria.ru/services/search/getmore/?query={query}&tags_limit=20&interval%5B%5D=year&sort%5B%5D=date` |
| Interfax | `https://www.interfax.ru/search/?df={from}&dt={to}&phrase={query}` |
| PRIME | `https://1prime.ru/services/search/getmore/?tags_limit=20&date_from={from}&date_to={to}&query={query}` |

Полный новостной workflow (приоритеты источников, фильтрация шума, каскад периодов) —
в skill `news-web` репо t-invest-agent, не здесь.

## Диагностика кодов

- 000 / connect timeout — сеть или блокировка способа; пробовать прокси.
- read timeout — сервер принял соединение и молчит; вариации UA/схемы, прокси.
- 403 — блокировка/гео/WAF; прокси (exit IP), затем Wayback.
- 429 — rate limit: пауза; прокси меняет IP; Wayback для архивных копий.
- 502 через прокси — прокси не достучался до origin; http-вариант, другой способ.
- JS-challenge — тело содержит «Just a moment»/«Client Challenge»; fetch.php
  считает такой 200 неуспехом и продолжает перебор способов.
- Сетевые errno curl 6 (DNS), 7 (connect), 28 (timeout) — признак блокировки
  способа, а не сайта: fetch.php пропускает вариации и идёт сразу в прокси.
  HTTP-отказы (403/429/5xx) — наоборот, сначала браузерный UA и смена схемы.
- TLS errno 60/35/77/80… — недоверие к сертификату (российские сайты на CA
  Минцифры): отчёт возвращает hint с официальной страницей установки
  (gosuslugi.ru/crt); прокси бесполезен, проверка локальная. Бандл в репо
  не вендорим (срок жизни: root 2032-02-27, sub 2027-03-06).