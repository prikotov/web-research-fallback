# Способы и кейсы (сеть владельца, проверено 2026-11-13…24)

| Цель | Direct | Прокси | Wayback | Примечание |
|---|---|---|---|---|
| youtube.com | connect timeout | 200 | — | прокси обязателен |
| archive.org API (metadata/available/CDX) | 200 | — | — | снапшоты web.archive.org могут 429 → прокси |
| api.github.com, suggestqueries.google.com | 200 | — | — | |
| cbr.ru, moex.com | 200 | — | — | см. cbr-core / moex-site-core |
| eutils.ncbi.nlm.nih.gov | 200 | — | — | |
| lite.duckduckgo.com/lite/ POST q= | 200 | 200 | — | html.duckduckgo.com/html/ GET — пусто |
| finam.ru | 403 (research UA); challenge (browser UA, перепроверено 2026-11-25) | challenge | 200 через chrome | WAF меняется: раньше хватало browser UA, теперь нужен chrome |
| rbc.ru | 200 с пустым телом для ботов | 200 через chrome | — | пустое тело триггерит chrome; `--virtual-time-budget` ломает RBC (0 байт) — не используем |
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
| api.tavily.com/search | доступен из РФ, нужен Bearer-ключ | да, движок tavily (ключ в конфиге) |
| api.search.brave.com/res/v1/web/search | доступен из РФ (422 без параметров), нужен X-Subscription-Token | да, движок brave (ключ в конфиге) |
| s.jina.ai (поиск) | direct 451 из РФ; без ключа 401 | да, движок jina: ключ + прокси |
| r.jina.ai (reader) | direct 451 из РФ; через прокси 200 markdown без ключа | да, способ jina в fetch.php |
| lite.duckduckgo.com/lite/ POST q= | 200, релевантно (cbr/moex/finam) | да, движок ddg |
| bing.com/search с mkt=ru-RU&setlang=ru | 200, релевантно, серверный HTML | да, движок bing |
| bing.com/search без mkt | 200, но словари «доля» | нет: рынок по умолчанию en |
| google.com/search | 200, страница «enablejs»; Chrome рендерит результаты, но реальные URL в шифрованных /goto-токенах (gbv=1 не помогает) | нет: URL источников не извлечь |
| ya.ru / yandex.ru/search | SmartCaptcha даже через headless Chrome | нет: captcha боту, обход запрещён |
| go.mail.ru/search | 200, JS-оболочка 1.8 КБ; через Chrome — оболочка авторизации | нет: результатов нет |

## JS-challenge и headless Chrome (проверено 2026-11-25)

| Сайт | curl/browser-UA | headless Chrome | Примечание |
|---|---|---|---|
| tass.ru/search | 200, оболочка servicepipe (js-challenge-loader, 1.8–2.4 КБ) | 200, ~590 КБ с результатами | UA chrome = Linux/146 (как в news-web); Win/126 → снова оболочка |
| google.com/search | 200, страница enablejs | 200, 1.4–2.7 МБ DOM с результатами, но URL в /goto-токенах | не пригоден для поиска: ссылки не извлечь |
| ya.ru / yandex.ru/search | SmartCaptcha | SmartCaptcha («Вы не робот?») | эмуляция не помогает |
| go.mail.ru/search | JS-оболочка | оболочка авторизации | эмуляция не помогает |

Вывод: эмуляция Chrome открывает контент-сайты (TASS), но не поисковики:
google прячет URL, yandex требует captcha, mail — авторизацию.
Feed-экспорты (RSS/Atom) проверены как способ и удалены: покрывают только свежие
item топ-30 лент и дублируют chrome.

## Reader-сервисы (проверено 2026-11-25)

| Сервис | Результат | Вердикт |
|---|---|---|
| r.jina.ai/<url> (через прокси) | finam: 200, 36 КБ markdown с текстом статьи | в цепочке (шаг jina) |
| markdown.new/<url> (Cloudflare) | 200, но markdown пустой на сайтах с WAF | не пригоден |
| api.allorigins.win/raw?url= | 522 | не пригоден |
| textise.net/showText.aspx | 403 | не пригоден |

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