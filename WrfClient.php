<?php

declare(strict_types=1);

/**
 * WrfClient — общий клиент web-research-fallback (цепочка доступа к недоступным сайтам).
 *
 * PHP 8.4+, ext-curl, без composer-зависимостей (как Ga4Client.php).
 * Секреты: прокси только из рантайма (--proxy / env WEB_RESEARCH_PROXY /
 * собственный конфиг .wrf_config.json); в stdout/логи/отчёты не выводятся.
 */
final class WrfClient
{
    public const UA = 'Mozilla/5.0 (research; web-research-fallback)';
    public const UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    public const CONFIG_NAME = '.wrf_config.json';
    private const CHALLENGE_MARKERS = ['just a moment', 'client challenge', 'checking your browser', 'enable javascript and cookies', 'access denied'];

    /** Путь собственного конфига: корень репо или WRF_CONFIG. */
    public static function configPath(): string
    {
        $env = getenv('WRF_CONFIG');
        return $env !== false && $env !== '' ? $env : __DIR__ . '/' . self::CONFIG_NAME;
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
     *  вариации UA/схемы на том же маршруте бессмысленны — сразу прокси. */
    public static function isNetworkError(string $note): bool
    {
        return (bool)preg_match('/curl errno (6|7|28)\b/', $note);
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

    /** Поиск через lite.duckduckgo.com/lite/ (POST q=). @return array{results:?array,status:int,note:string} */
    public static function ddgLite(string $query, ?string $proxy = null, int $limit = 10): array
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