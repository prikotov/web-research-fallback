# AGENTS.md — для ИИ-агентов, разрабатывающих этот репозиторий

## Что это за проект

**web-research-fallback** — agent-skill: доступ к веб-источникам, к которым
затруднён доступ (перебор способов: direct → прокси → headless Chrome →
reader-сервисы → Wayback), и поиск источников для агентов
(tavily/brave/jina/bing/ddg). PHP 8.4+, ext-curl, без composer.

Документация по адресатам: `SKILL.md` — инструкции для агентов-пользователей,
`README.md` — для людей, этот файл — для разработки самого скилла.
Справочник поведения сайтов и способов — `references/methods.md`
(только переиспользуемое знание; сетевые пробы и тестовые запросы владельца
туда не пишутся).

Скилл устанавливается в рабочие проекты (симлинком или git-клоном). После мержа
в `main` синхронизируй установленные git-клоны (`git pull --ff-only`); симлинки
обновляются сами.

## Архитектура

```
WrfClient.php        — общий клиент: конфиг/прокси, fetch, детекторы
                       (challenge/js-shell/empty body/сеть/TLS), chrome,
                       wayback, поисковые движки, reader/extract
scripts/fetch.php    — перебор способов доступа к URL; тело в файл, JSON-отчёт
scripts/search.php   — поиск источников; JSON со списком кандидатов
references/methods.md — справочник: сайты, движки, reader-сервисы, коды
tests/run.php        — офлайн-тесты чистых функций; прод не трогают
```

**Контракт вывода `fetch.php`** (stdout, машиночитаемый, не ломать):
`url, ok, method, status, bytes, out, final_url, proxy_available, hint,
attempts[{method,status,note}]`. Пометки note: `challenge`, `js-shell`,
`empty body`, `curl errno N`, `no snapshot`. Код выхода 0/1.

**Контракт вывода `search.php`**: `query, engine, method, status, note,
results[{title,url,snippet}]`. Код выхода 0/1. Сниппеты — только навигация.

Диагностика и предупреждения — в stderr, не в JSON-отчёт.

## Правила разработки

- Прямые коммиты в `main` запрещены (branch protection): ветка → PR → merge.
- Секреты — только `.wrf_config.json` (в `.gitignore`, права 600) или рантайм-env
  `WEB_RESEARCH_PROXY`; в коде, доке и коммитах секретов нет.
- Не отключать проверку TLS (`-k`/VERIFYPEER=false); при TLS-отказе — `hint`
  с официальной ссылкой установки сертификатов.
- Не обходить подписки/авторизацию/captcha; массовый сбор через `/search/`
  сайтов запрещён (robots) — только точечные запросы.
- Новое поведение сайтов и способов фиксировать в `references/methods.md`
  с датой проверки; отрицательные результаты — тоже.
- Офлайн-тесты (`php tests/run.php`) и `php -l` должны проходить до PR;
  CI гоняет их на каждый push/PR.
