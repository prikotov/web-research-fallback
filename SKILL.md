---
name: web-research-fallback
description: "Доступ к недоступным сайтам при веб-исследованиях — диагностика ошибки, повтор через прокси, варианты URL, Wayback Machine, поиск через lite.duckduckgo. Применяй в любом проекте, когда страница не грузится (timeout, 000, 403, 502, 429, JS-challenge) и нужно проверить источник, а не сдаваться."
license: MIT
---

# Web Research Fallback

Переносимый скилл+скрипты для доступа к недоступным источникам. Репо
клонируется/симлинкуется в каталог skills любого агента; секретов в репо нет —
прокси берётся в рантайме из окружения или локального env-файла проекта.

## Скрипты

```bash
python3 scripts/fetch.py URL [--out FILE] [--proxy URL] [--no-wayback]
python3 scripts/search.py "запрос" [--limit 10]
```

- `fetch.py` — цепочка: direct → вариации (UA/схема/www) → прокси → Wayback
  (available API → снапшот; при 429 снапшот через прокси). JSON-отчёт:
  маршрут, статус, байты, путь тела, список попыток. Код выхода 0/1.
- `search.py` — lite.duckduckgo.com/lite/ POST `q=` (direct → прокси);
  JSON-список {title, url, snippet}.
- Прокси: `--proxy` → env `WEB_RESEARCH_PROXY`/`YT_DLP_PROXY` → файлы
  `.env.dev.local`/`.env.local`/`.env` в **текущем каталоге** (ключи те же).
  Значение не выводится ни в stdout, ни в отчёты, ни в файлы.

## Когда использовать

Сайт не отвечает при исследовании: connect/read timeout, HTTP 000/403/502/429,
JS-challenge («Just a moment», «Client Challenge»). Не делай вывод «источник
недоступен» после первой попытки — пройди цепочку (скрипт делает её сам).

## Цепочка доступа (порядок)

1. **Диагностируй ошибку.** Различай connect timeout (сеть/блокировка), read
   timeout (сервер молчит), 403 (блок/гео), 429 (rate limit), 502 (прокси не
   достучался до origin). 🔴 Никогда `curl -v` с прокси: debug печатает URL
   прокси с учётными данными.
2. **Повтори напрямую с вариациями:** браузерный UA, http/https, www/без www,
   timeout до 45–60 с.
3. **Повтори через прокси** (меняет exit IP: гео-блоки и 429 по IP). Пробуй
   https и http через прокси.
4. **Сайт мёртв на обоих маршрутах → Wayback:** `archive.org/wayback/available`
   (обычно direct), CDX `web.archive.org/cdx/search/cdx?url=…&filter=statuscode:200`,
   снапшот `web.archive.org/web/<ts>/<URL>`; при 429 — через прокси. Пагинацию
   и `?singlepage=1` проверяй внутри снапшота.
5. **JS-challenge (Scribd и т.п.):** Wayback; не вышло — статус «не проверено»,
   содержимое не додумывать.
6. **Зафиксируй маршрут и дату** в карточке источника/отчёте проекта: что
   пробовал, итог «проверено (маршрут, снапшот)» или «не проверено». Отсутствие
   доступа ≠ отсутствие содержимого; сниппет поисковика ≠ проверенный факт.

## Безопасность

- Секреты — только рантайм из env; не в код, коммиты, логи, отчёты, заметки.
- Временные файлы — в `/tmp` (fetch.py по умолчанию), не мусорить в проекте.
- Не обходить платные подписки и авторизацию; Wayback — публичный архив.
- Уважать rate limit: паузы, не долбить 429.

## Известные особенности сети владельца

Проверено 2026-11-13…24 (TasK, ai-trading-sources): YouTube и часть сайтов —
direct connect timeout, через прокси 200; archive.org API — direct, снапшоты
могут 429 → прокси; `api.github.com`, `cbr.ru`, `moex.com`,
`eutils.ncbi.nlm.nih.gov` — direct; `lite.duckduckgo.com/lite/` POST — direct
работает, `html.duckduckgo.com/html/` GET — пусто; finam.ru — 403/JS-challenge,
Reuters-перепечатки доступны через Wayback+прокси (cp1251). Подробности и
маршруты кейсов — `references/routes.md`.