<?php

declare(strict_types=1);

/**
 * search.php — поиск источников через lite.duckduckgo.com (POST q=), direct → прокси.
 *
 *   php scripts/search.php "запрос" [--limit 10] [--engine auto|tavily|brave|jina|bing|ddg] [--proxy URL]
 *
 * JSON {query, engine, method, status, note, results:[{title,url,snippet}]}.
 * auto: tavily → brave → jina (только прокси) → bing → ddg;
 * tavily/brave пропускаются без ключей в конфиге, jina — без прокси (451 из РФ).
 * google/yandex/mail.ru не используем: enablejs / captcha / оболочка авторизации;
 * эмуляция Chrome не помогает (проверено 2026-11-25, см. references/methods.md).
 */
require_once dirname(__DIR__) . '/WrfClient.php';

[$pos, $opt] = WrfClient::argv($argv);
if (!isset($pos[0])) {
    fwrite(STDERR, "usage: php scripts/search.php \"запрос\" [--limit N] [--proxy URL]\n");
    exit(2);
}
$query = $pos[0];
$limit = (int)($opt['limit'] ?? 10);
$engineOpt = (string)($opt['engine'] ?? 'auto');
WrfClient::ensureConfigIgnored();
$proxy = WrfClient::loadProxy(isset($opt['proxy']) ? (string)$opt['proxy'] : null);

$engines = $engineOpt === 'auto' ? ['tavily', 'brave', 'jina', 'bing', 'ddg'] : [$engineOpt];
$r = ['results' => null, 'status' => 0, 'note' => 'unknown engine'];
$engineUsed = null;
$method = null;
foreach ($engines as $eng) {
    if ($eng === 'jina') {
        $r = WrfClient::jinaSearch($query, $proxy, $limit);
        $method = 'jina';
    } else {
        $call = match ($eng) {
            'tavily' => static fn (?string $p) => WrfClient::tavilySearch($query, $p, $limit),
            'brave' => static fn (?string $p) => WrfClient::braveSearch($query, $p, $limit),
            'bing' => static fn (?string $p) => WrfClient::bingSearch($query, $p, $limit),
            default => static fn (?string $p) => WrfClient::ddgLite($query, $p, $limit),
        };
        $r = $call(null);
        $method = $eng;
        if ($r['results'] === null && $proxy !== null) {
            $r = $call($proxy);
            $method = $eng . '-proxy';
        }
    }
    if ($r['results'] !== null) {
        $engineUsed = $eng;
        break;
    }
}
WrfClient::emit([
    'query' => $query,
    'engine' => $engineUsed,
    'method' => $r['results'] !== null ? $method : null,
    'status' => $r['status'],
    'note' => $r['note'],
    'results' => $r['results'] ?? [],
]);
exit($r['results'] !== null ? 0 : 1);