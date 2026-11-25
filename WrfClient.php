<?php

declare(strict_types=1);

/**
 * WrfClient — общий клиент web-research-fallback (перебор способов доступа к недоступным сайтам).
 *
 * PHP 8.4+, ext-curl, без composer-зависимостей (как Ga4Client.php).
 * Секреты: прокси только из рантайма (--proxy / env WEB_RESEARCH_PROXY /
 * собственный конфиг .wrf_config.json); в stdout/логи/отчёты не выводятся.
 */
final class WrfClient
{
    public const UA = 'Mozilla/5.0 (research; web-research-fallback)';
    public const UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    /** UA для headless Chrome: должен совпадать с платформой/версией реального
     *  браузера, иначе JS-challenge фингерпринтит несоответствие (проверено:
     *  Win/126 → оболочка 2.4 КБ, Linux/146 → 589 КБ контента). Строка из news-web. */
    public const CHROME_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
    /** Полный набор заголовков браузера: некоторые WAF смотрят не только на UA. */
    public const BROWSER_HEADERS = [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'none',
        'Sec-Fetch-User' => '?1',
        'Upgrade-Insecure-Requests' => '1',
        'sec-ch-ua' => '"Chromium";v="126", "Google Chrome";v="126", "Not.A/Brand";v="24"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"Windows"',
    ];
    public const CONFIG_NAME = '.wrf_config.json';
    /** Официальная страница установки сертификатов НУЦ Минцифры (Госуслуги — портал Минцифры). */
    public const RU_CA_INSTALL_URL = 'https://www.gosuslugi.ru/crt';
    private const CHALLENGE_MARKERS = ['just a moment', 'client challenge', 'checking your browser', 'enable javascript and cookies', 'access denied', 'enablejs', 'js-challenge-loader', 'servicepipe.tech'];

    /** Путь собственного конфига: корень репо или WRF_CONFIG. */
    public static function configPath(): string
    {
        $env = getenv('WRF_CONFIG');
        return $env !== false && $env !== '' ? $env : __DIR__ . '/' . self::CONFIG_NAME;
    }

    /** Конфиг скилла целиком (proxy + ключи поисковых API). */
    public static function config(): array
    {
        $p = self::configPath();
        if (!is_file($p)) {
            return [];
        }
        $d = json_decode((string)file_get_contents($p), true);
        return is_array($d) ? $d : [];
    }

    public static function configKey(string $key): ?string
    {
        $v = self::config()[$key] ?? null;
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : null;
    }

    /** Прокси: --proxy → env WEB_RESEARCH_PROXY → конфиг скилла. null если нет. */
    public static function loadProxy(?string $explicit = null): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }
        $env = getenv('WEB_RESEARCH_PROXY');
        if ($env !== false && trim($env) !== '') {
            return trim(trim($env), "\"'");
        }
        $p = self::configPath();
        if (is_file($p)) {
            $d = json_decode((string)file_get_contents($p), true);
            $v = is_array($d) ? (string)($d['proxy'] ?? '') : '';
            $v = trim(trim($v), "\"'");
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Конфиг с секретом должен быть в .gitignore репо/каталога, где лежит.
     * Приём из Ga4Client.php: git check-ignore, иначе пополняем .gitignore
     * именем файла (не значением); предупреждение — в STDERR.
     */
    public static function ensureConfigIgnored(?string $path = null): bool
    {
        $p = realpath($path ?? self::configPath());
        if ($p === false) {
            $p = $path ?? self::configPath();
        }
        $dir = dirname($p);
        $entry = basename($p);
        $top = null;
        exec('git -C ' . escapeshellarg($dir) . ' rev-parse --show-toplevel 2>/dev/null', $out, $rc);
        if ($rc === 0 && isset($out[0])) {
            $top = trim($out[0]);
        }
        if ($top !== null) {
            $rel = ltrim(substr($p, strlen($top)), '/');
            exec('git -C ' . escapeshellarg($top) . ' check-ignore -q -- ' . escapeshellarg($rel) . ' 2>/dev/null', $o2, $rc2);
            if ($rc2 === 0) {
                return true;
            }
            $gi = $top . '/.gitignore';
        } else {
            $gi = $dir . '/.gitignore';
        }
        $lines = is_file($gi) ? array_map('trim', file($gi, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
        if (in_array($entry, $lines, true)) {
            return true;
        }
        $content = is_file($gi) ? rtrim((string)file_get_contents($gi)) . "\n" : '';
        file_put_contents($gi, $content . $entry . "\n");
        fwrite(STDERR, "⚠️  .gitignore пополнен записью {$entry}: {$gi}\n");
        return false;
    }

    /**
     * GET/POST через curl; прокси любой схемы (http/https/socks) поддерживает CURLOPT_PROXY.
     * @return array{body:string,status:int,note:string}
     */
    public static function fetch(string $url, ?string $proxy = null, string $ua = self::UA, int $timeout = 20, ?string $data = null, array $headers = []): array
    {
        $ch = curl_init($url);
        $hdrs = $headers;
        if ($data !== null && !isset($hdrs['Content-Type'])) {
            $hdrs['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $curlHeaders = [];
        foreach ($hdrs as $k => $v) {
            $curlHeaders[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $curlHeaders,
        ]);
        if ($proxy !== null && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($body === false) {
            // сообщение curl может содержать адрес прокси — в отчёт только errno
            return ['body' => '', 'status' => 0, 'note' => 'curl errno ' . $errno, 'url' => $url];
        }
        return ['body' => (string)$body, 'status' => $status, 'note' => $status === 0 ? 'curl errno ' . $errno : '', 'url' => $effective];
    }

    public static function isChallenge(string $body): bool
    {
        $head = strtolower(substr($body, 0, 4000));
        foreach (self::CHALLENGE_MARKERS as $m) {
            if (str_contains($head, $m)) {
                return true;
            }
        }
        return false;
    }

    /** JS-оболочка: 200 и тело есть, но контент рендерится только браузером
     *  (TASS: 1.8 КБ с div#root против 619 КБ DOM с результатами). */
    public static function isJsShell(string $body): bool
    {
        return strlen($body) < 5000 && (bool)preg_match('~<div id="(root|app|__next)"~', $body);
    }

    /** Headless Chrome для JS-челленджей (приём из skills/news-web t-invest-agent). */
    public static function chromeBin(): ?string
    {
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $bin) {
            exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $rc);
            if ($rc === 0 && isset($out[0])) {
                return trim($out[0]);
            }
        }
        return null;
    }

    public static function chromeFetch(string $url, ?string $proxy = null, int $timeoutMs = 30000): array
    {
        $bin = self::chromeBin();
        if ($bin === null) {
            return ['body' => '', 'status' => 0, 'note' => 'no chrome binary', 'url' => $url];
        }
        $cmd = escapeshellarg($bin) . ' --headless=new --disable-gpu --disable-blink-features=AutomationControlled'
            . ' --window-size=1920,1080 --user-agent=' . escapeshellarg(self::CHROME_UA)
            . ' --dump-dom --timeout=' . (int)$timeoutMs;
        if ($proxy !== null && $proxy !== '') {
            $cmd .= ' --proxy-server=' . escapeshellarg($proxy);
        }
        $cmd .= ' ' . escapeshellarg($url) . ' 2>/dev/null';
        exec($cmd, $lines, $rc);
        $body = implode("\n", $lines);
        if ($rc !== 0 || $body === '') {
            return ['body' => '', 'status' => 0, 'note' => 'chrome exit ' . $rc, 'url' => $url];
        }
        return ['body' => $body, 'status' => 200, 'note' => '', 'url' => $url];
    }

    /** Смена схемы http/https: legacy-сайты только по http или битый TLS.
     *  www-вариант не нужен: CURLOPT_FOLLOWLOCATION сам следует редиректам www. */
    public static function urlVariants(string $url): array
    {
        if (!preg_match('~^(https?)://(.*)$~', $url, $m)) {
            return [];
        }
        [, $scheme, $rest] = $m;
        $other = $scheme === 'https' ? 'http' : 'https';
        return ["$other://{$rest}"];
    }

    /** Сетевая ошибка (DNS/connect/timeout): типична для исходящих блокировок из РФ;
     *  вариации UA/схемы на том же способе бессмысленны — сразу прокси. */
    public static function isNetworkError(string $note): bool
    {
        return (bool)preg_match('/curl errno (6|7|28)\b/', $note);
    }

    /** TLS-отказ (errno 60 и род.): российские сайты на сертификатах НУЦ Минцифры,
     *  которых нет в дефолтном trust store. Лечится бандлом российских CA,
     *  а не прокси: доверие к сертификату проверяется локально. */
    public static function isTlsError(string $note): bool
    {
        return (bool)preg_match('/curl errno (35|51|58|60|77|80|82|83|90|91)\b/', $note);
    }

    /** Ближайший снапшот Wayback (API обычно доступен напрямую). */
    public static function waybackSnapshot(string $url): ?string
    {
        $r = self::fetch('http://archive.org/wayback/available?url=' . rawurlencode($url));
        if ($r['status'] !== 200) {
            return null;
        }
        $d = json_decode($r['body'], true);
        $snap = $d['archived_snapshots']['closest'] ?? null;
        return is_array($snap) && !empty($snap['available']) ? (string)$snap['url'] : null;
    }

    /** Поиск через lite.duckduckgo.com/lite/ (POST q=). @return array{results:?array,status:int,note:string} */    public static function ddgLite(string $query, ?string $proxy = null, int $limit = 10): array
    {
        $r = self::fetch('https://lite.duckduckgo.com/lite/', $proxy, self::UA, 20, http_build_query(['q' => $query]));
        if ($r['status'] !== 200 || $r['body'] === '') {
            return ['results' => null, 'status' => $r['status'], 'note' => $r['note']];
        }
        $html = $r['body'];
        $out = [];
        preg_match_all('~<a(?=[^>]*class=["\']result-link["\'])[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~s', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $url = $m[1];
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            }
            $out[] = ['title' => trim(preg_replace('/\s+/', ' ', strip_tags($m[2]))), 'url' => $url];
        }
        preg_match_all('~class=["\']result-snippet["\'][^>]*>(.*?)</td>~s', $html, $sn, PREG_SET_ORDER);
        foreach ($sn as $i => $s) {
            if (isset($out[$i])) {
                $out[$i]['snippet'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($s[1]))), 0, 300);
            }
        }
        return ['results' => array_slice($out, 0, $limit), 'status' => 200, 'note' => ''];
    }

    /** Brave Search API: свой индекс, JSON, ключ X-Subscription-Token. */
    public static function braveSearch(string $query, ?string $proxy = null, int $limit = 10): array
    {
        $key = self::configKey('brave_api_key');
        if ($key === null) {
            return ['results' => null, 'status' => 0, 'note' => 'no brave_api_key in config'];
        }
        $r = self::fetch('https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode($query) . '&count=' . $limit, $proxy, self::UA, 25, null, ['X-Subscription-Token' => $key, 'Accept' => 'application/json']);
        if ($r['status'] !== 200) {
            return ['results' => null, 'status' => $r['status'], 'note' => $r['note']];
        }
        $d = json_decode($r['body'], true);
        $out = [];
        foreach (($d['web']['results'] ?? []) as $it) {
            $out[] = ['title' => (string)($it['title'] ?? ''), 'url' => (string)($it['url'] ?? ''), 'snippet' => mb_substr((string)($it['description'] ?? ''), 0, 300)];
        }
        return ['results' => $out ?: null, 'status' => 200, 'note' => ''];
    }

    /** Tavily: search API для агентов, ключ Bearer. */
    public static function tavilySearch(string $query, ?string $proxy = null, int $limit = 10): array
    {
        $key = self::configKey('tavily_api_key');
        if ($key === null) {
            return ['results' => null, 'status' => 0, 'note' => 'no tavily_api_key in config'];
        }
        $body = json_encode(['query' => $query, 'max_results' => $limit, 'search_depth' => 'basic']);
        $r = self::fetch('https://api.tavily.com/search', $proxy, self::UA, 30, $body, ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json']);
        if ($r['status'] !== 200) {
            return ['results' => null, 'status' => $r['status'], 'note' => $r['note']];
        }
        $d = json_decode($r['body'], true);
        $out = [];
        foreach (($d['results'] ?? []) as $it) {
            $out[] = ['title' => (string)($it['title'] ?? ''), 'url' => (string)($it['url'] ?? ''), 'snippet' => mb_substr((string)($it['content'] ?? ''), 0, 300)];
        }
        return ['results' => $out ?: null, 'status' => 200, 'note' => ''];
    }

    /** Jina s.jina.ai: поиск для агентов; из РФ direct = 451, только через прокси.
     *  Поиск требует ключ (401 без него); reader r.jina.ai — без ключа. */
    public static function jinaSearch(string $query, ?string $proxy = null, int $limit = 10): array
    {
        $key = self::configKey('jina_api_key');
        if ($key === null) {
            return ['results' => null, 'status' => 0, 'note' => 'no jina_api_key in config'];
        }
        if ($proxy === null) {
            return ['results' => null, 'status' => 0, 'note' => 'jina requires proxy (451 from RU)'];
        }
        $h = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $key];
        $r = self::fetch('https://s.jina.ai/' . rawurlencode($query), $proxy, self::UA, 40, null, $h);
        if ($r['status'] !== 200) {
            return ['results' => null, 'status' => $r['status'], 'note' => $r['note']];
        }
        $d = json_decode($r['body'], true);
        $out = [];
        foreach (array_slice(($d['data'] ?? []), 0, $limit) as $it) {
            $out[] = ['title' => (string)($it['title'] ?? ''), 'url' => (string)($it['url'] ?? ''), 'snippet' => mb_substr((string)($it['description'] ?? $it['content'] ?? ''), 0, 300)];
        }
        return ['results' => $out ?: null, 'status' => 200, 'note' => ''];
    }

    /** Tavily extract: тело страницы через их рендер; нужен ключ, из РФ — прокси. */
    public static function tavilyExtract(string $url, ?string $proxy = null): ?array
    {
        $key = self::configKey('tavily_api_key');
        if ($key === null) {
            return null;
        }
        $ch = curl_init('https://api.tavily.com/extract');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['urls' => [$url]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_USERAGENT => self::UA,
        ]);
        if ($proxy !== null) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) {
            return null;
        }
        $data = json_decode($body, true);
        $raw = $data['results'][0]['raw_content'] ?? null;
        if (!is_string($raw) || strlen($raw) < 500) {
            return null;
        }
        return ['body' => $raw, 'status' => 200];
    }

    /** Jina r.jina.ai: reader, рендерит JS и отдаёт markdown; из РФ только прокси. */
    public static function jinaReader(string $url, ?string $proxy = null): array
    {
        if ($proxy === null) {
            return ['body' => '', 'status' => 0, 'note' => 'jina requires proxy (451 from RU)'];
        }
        $h = ['Accept' => 'application/json'];
        $key = self::configKey('jina_api_key');
        if ($key !== null) {
            $h['Authorization'] = 'Bearer ' . $key;
        }
        $r = self::fetch('https://r.jina.ai/' . $url, $proxy, self::UA, 45, null, $h);
        if ($r['status'] !== 200) {
            return ['body' => '', 'status' => $r['status'], 'note' => $r['note']];
        }
        $d = json_decode($r['body'], true);
        $md = (string)($d['data']['content'] ?? $d['data']['markdown'] ?? '');
        if ($md === '') {
            return ['body' => '', 'status' => 200, 'note' => 'empty reader content'];
        }
        return ['body' => $md, 'status' => 200, 'note' => ''];
    }

    /** Поиск Bing: серверный HTML, рынок ru-RU обязателен (без него словари).
     *  URL органики лежит в редиректе /ck/a?…u=a1<base64url>. */
    public static function bingSearch(string $query, ?string $proxy = null, int $limit = 10): array
    {
        $url = 'https://www.bing.com/search?q=' . rawurlencode($query) . '&mkt=ru-RU&setlang=ru';
        $r = self::fetch($url, $proxy, self::UA_BROWSER, 25);
        if ($r['status'] !== 200 || $r['body'] === '') {
            return ['results' => null, 'status' => $r['status'], 'note' => $r['note']];
        }
        $out = [];
        preg_match_all('~<li class="b_algo".*?</li>~s', $r['body'], $bm, PREG_SET_ORDER);
        foreach ($bm as $b0) {
            $b = $b0[0];
            if (!preg_match('~<h2[^>]*>.*?<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>~s', $b, $m)) {
                continue;
            }
            $href = html_entity_decode($m[1], ENT_QUOTES);
            $target = $href;
            if (preg_match('/[?&]u=a1([^&"]+)/', $href, $u)) {
                $dec = base64_decode(strtr($u[1], '-_', '+/'));
                if ($dec !== false && str_starts_with($dec, 'http')) {
                    $target = $dec;
                }
            }
            $title = trim(preg_replace('/\s+/', ' ', strip_tags($m[2])));
            $snippet = '';
            if (preg_match('~<p[^>]*>(.*?)</p>~s', $b, $p)) {
                $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($p[1]))), 0, 300);
            }
            $out[] = ['title' => $title, 'url' => $target, 'snippet' => $snippet];
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['results' => $out ?: null, 'status' => 200, 'note' => ''];
    }

    /** JSON-отчёт в stdout (без секретов). */
    public static function emit(array $report): void
    {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }

    /** Разбор простых CLI-аргументов: позиционные + --key value / --flag. */
    public static function argv(array $argv): array
    {
        $pos = [];
        $opt = [];
        $n = count($argv);
        for ($i = 1; $i < $n; $i++) {
            $a = $argv[$i];
            if (str_starts_with($a, '--')) {
                $k = substr($a, 2);
                if ($i + 1 < $n && !str_starts_with($argv[$i + 1], '--')) {
                    $opt[$k] = $argv[++$i];
                } else {
                    $opt[$k] = true;
                }
            } else {
                $pos[] = $a;
            }
        }
        return [$pos, $opt];
    }
}