# Web Research Fallback

> Переносимый скилл и скрипты доступа к недоступным веб-источникам:
> диагностика → вариации URL/UA → прокси → Wayback Machine; поиск через
> lite.duckduckgo. Для любого проекта и агента.

Структура — как у `cbr-core` / `google-analytics-core`: корневой `SKILL.md`,
скрипты в `scripts/`, справка маршрутов в `references/`. Только Python stdlib.

## Установка

```bash
git clone https://github.com/prikotov/web-research-fallback.git .agents/skills/web-research-fallback
# или симлинк на существующий клон (в любом проекте):
ln -s /home/dp/MyProjects/web-research-fallback .agents/skills/web-research-fallback
# общий каталог скиллов нескольких проектов:
ln -s /home/dp/MyProjects/web-research-fallback /home/dp/MyProjects/.ai_sync/.agents/skills/web-research-fallback
```

## Настройка прокси (в проекте-потребителе, не здесь)

Прокси-строка кладётся в локальный env-файл проекта (`.env.dev.local`,
`.env.local` или `.env`; ключ `WEB_RESEARCH_PROXY` или `YT_DLP_PROXY`), файл —
в `.gitignore` проекта, права 600. Либо переменная окружения, либо `--proxy`.
В этом репо секретов нет и не должно быть.

## Использование

```bash
python3 scripts/fetch.py https://example.com/article --out /tmp/a.html
# {"ok": true, "route": "proxy", "status": 200, "bytes": …, "attempts": […]}
python3 scripts/search.py "moex retail investors share 2021" --limit 8
```

`fetch.py` сам проходит цепочку и печатает JSON-отчёт с маршрутами попыток
(без секретов); тело сохраняет в `--out` или `/tmp/wrf_<hash>.html`.
`search.py` — JSON-список результатов ddg lite.

## Правила

- Не выводить прокси/токены в stdout, логи, файлы, отчёты; никаких `curl -v` с прокси.
- Не обходить подписки/авторизацию; Wayback — публичный архив.
- Rate limit уважать; временные файлы — в `/tmp`.
- «Не проверено» фиксировать явно; сниппет поисковика не выдавать за факт.

Подробнее — [SKILL.md](SKILL.md), маршруты и кейсы — [references/routes.md](references/routes.md).