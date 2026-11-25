# Способы доступа: справочник (проверено 2026-11-25)

Только переиспользуемое знание: поведение важных сайтов и способов.
Сетевые пробы и тестовые запросы владельца сюда не пишутся.

## Важные сайты: поведение и рабочий способ

| Сайт | Поведение для ботов | Рабочий способ |
|---|---|---|
| finam.ru | 403 / JS-challenge; WAF ужесточился: browser UA уже не хватает | chrome |
| rbc.ru | 200 с пустым телом | chrome; `--virtual-time-budget` ломает RBC (0 байт) — не используем |
| tass.ru | 200, JS-оболочка servicepipe (1.8–2.4 КБ) | chrome; UA chrome обязан совпадать с платформой/версией реального браузера |
| scribd.com | JS-challenge и direct, и proxy | способ не найден |
| archive.ph / archive.today | 000 и direct, и proxy из РФ | недоступен; в перебор не добавлен |
| sberbank.ru, gosuslugi.ru, digital.gov.ru | TLS errno 60 без российских CA в trust store | системная установка сертификатов НУЦ Минцифры (gosuslugi.ru/crt); прокси не лечит |
| web.archive.org (снапшоты) | 429 при частых запросах | прокси; снапшоты бывают в cp1251 — декодировать по charset |

## Поисковые движки

| Движок | Результат из РФ | Пригодность |
|---|---|---|
| api.tavily.com/search | доступен, нужен Bearer-ключ | да, движок tavily (ключ в конфиге) |
| api.search.brave.com/res/v1/web/search | доступен, нужен X-Subscription-Token | да, движок brave (ключ в конфиге) |
| s.jina.ai (поиск) | direct 451; без ключа 401 | да, движок jina: ключ + прокси |
| r.jina.ai (reader) | direct 451; через прокси 200 markdown без ключа | да, способ jina в fetch.php |
| lite.duckduckgo.com/lite/ POST q= | 200, релевантно | да, движок ddg |
| bing.com/search с mkt=ru-RU&setlang=ru | 200, релевантно, серверный HTML | да, движок bing |
| bing.com/search без mkt | 200, но рынок по умолчанию en | нет: нерелевантно для русских запросов |
| google.com/search | enablejs; Chrome рендерит, но URL в шифрованных /goto-токенах | нет: URL источников не извлечь |
| ya.ru / yandex.ru/search | SmartCaptcha даже через headless Chrome | нет: captcha боту, обход запрещён |
| go.mail.ru/search | JS-оболочка; через Chrome — оболочка авторизации | нет: результатов нет |

## JS-challenge и headless Chrome

| Сайт | curl/browser-UA | headless Chrome |
|---|---|---|
| tass.ru | 200, оболочка servicepipe | 200, полный DOM с результатами |
| google.com/search | 200, enablejs | DOM с результатами, но URL в /goto-токенах |
| ya.ru / yandex.ru/search | SmartCaptcha | SmartCaptcha |
| go.mail.ru/search | JS-оболочка | оболочка авторизации |

Вывод: эмуляция Chrome открывает контент-сайты, но не поисковики:
google прячет URL, yandex требует captcha, mail — авторизацию.
Feed-экспорты (RSS/Atom) проверены как способ и удалены: покрывают только свежие
item топ-30 лент и дублируют chrome.

## Reader-сервисы

| Сервис | Результат | Вердикт |
|---|---|---|
| r.jina.ai/<url> (через прокси) | 200, markdown с текстом статьи | в цепочке (шаг jina) |
| api.tavily.com/extract (ключ) | 401 без ключа — эндпоинт существует | в цепочке (шаг tavily), ждёт ключ |
| brave search API | только поиск, тела страниц не отдаёт | только search.php |
| markdown.new/<url> (Cloudflare) | 200, но markdown пустой на сайтах с WAF | не пригоден |
| api.allorigins.win/raw?url= | 522 | не пригоден |
| textise.net/showText.aspx | 403 | не пригоден |

Chrome-способ включается автоматически при пометках challenge/js-shell/empty body;
`--no-chrome` отключает. Авторизованный прокси chrome не поддерживает.

## Внутри-сайтовый поиск

Альтернативный вид поиска: у многих сайтов есть собственный поиск,
и он отдаёт HTML без JS. Шаблоны проверенных сайтов:

| Сайт | Шаблон |
|---|---|
| Kommersant | `https://www.kommersant.ru/Search/Results?search_query={query}` |
| RIA | `https://ria.ru/services/search/getmore/?query={query}&tags_limit=20&interval%5B%5D=year&sort%5B%5D=date` |
| Interfax | `https://www.interfax.ru/search/?df={from}&dt={to}&phrase={query}` |
| PRIME | `https://1prime.ru/services/search/getmore/?tags_limit=20&date_from={from}&date_to={to}&query={query}` |

## Диагностика кодов

- 000 / connect timeout — сеть или блокировка способа; пробовать прокси.
- read timeout — сервер принял соединение и молчит; вариации UA/схемы, прокси.
- 403 — блокировка/гео/WAF; прокси (exit IP), затем Wayback.
- 429 — rate limit: пауза; прокси меняет IP; Wayback для архивных копий.
- 502 через прокси — прокси не достучался до origin; http-вариант, другой способ.
- JS-challenge — тело содержит «Just a moment»/«Client Challenge»; fetch.php
  считает такой 200 неуспехом и продолжает перебор способов.
- Пустое тело при 200 — тоже отказ: триггерит chrome и reader-сервисы.
- Сетевые errno curl 6 (DNS), 7 (connect), 28 (timeout) — признак блокировки
  способа, а не сайта: fetch.php пропускает вариации и идёт сразу в прокси.
  HTTP-отказы (403/429/5xx) — наоборот, сначала браузерный UA и смена схемы.
- TLS errno 60/35/77/80… — недоверие к сертификату (российские сайты на CA
  Минцифры): отчёт возвращает hint с официальной страницей установки
  (gosuslugi.ru/crt); прокси бесполезен, проверка локальная.
