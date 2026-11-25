<?php

declare(strict_types=1);

/**
 * search.php — поиск источников через lite.duckduckgo.com (POST q=), direct → прокси.
 *
 *   php scripts/search.php "запрос" [--limit 10] [--proxy URL]
 *
 * JSON {query, route, status, note, results:[{title,url,snippet}]}.
 */
require_once dirname(__DIR__) . '/WrfClient.php';

[$pos, $opt] = WrfClient::argv($argv);
if (!isset($pos[0])) {
    fwrite(STDERR, "usage: php scripts/search.php \"запрос\" [--limit N] [--proxy URL]\n");
    exit(2);
}
$query = $pos[0];
$limit = (int)($opt['limit'] ?? 10);
WrfClient::ensureConfigIgnored();
$proxy = WrfClient::loadProxy(isset($opt['proxy']) ? (string)$opt['proxy'] : null);

$r = WrfClient::ddgLite($query, null, $limit);
$route = 'direct';
if ($r['results'] === null && $proxy !== null) {
    $r = WrfClient::ddgLite($query, $proxy, $limit);
    $route = 'proxy';
}
WrfClient::emit([
    'query' => $query,
    'route' => $r['results'] !== null ? $route : null,
    'status' => $r['status'],
    'note' => $r['note'],
    'results' => $r['results'] ?? [],
]);
exit($r['results'] !== null ? 0 : 1);