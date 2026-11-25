<?php

declare(strict_types=1);

/**
 * fetch.php — цепочка доступа: direct → вариации → прокси → Wayback.
 *
 *   php scripts/fetch.php URL [--out FILE] [--proxy URL] [--timeout 20] [--no-wayback]
 *
 * JSON-отчёт {url, ok, route, status, bytes, out, proxy_available, attempts}.
 * Секреты в отчёт не попадают. Код выхода 0/1.
 */
require_once dirname(__DIR__) . '/WrfClient.php';

[$pos, $opt] = WrfClient::argv($argv);
if (!isset($pos[0])) {
    fwrite(STDERR, "usage: php scripts/fetch.php URL [--out FILE] [--proxy URL] [--timeout N] [--no-wayback]\n");
    exit(2);
}
$url = $pos[0];
$timeout = (int)($opt['timeout'] ?? 20);
WrfClient::ensureConfigIgnored();
$proxy = WrfClient::loadProxy(isset($opt['proxy']) ? (string)$opt['proxy'] : null);

$attempts = [];
$result = null;

$try = function (string $route, string $u, bool $useProxy = false, string $ua = WrfClient::UA, ?int $t = null) use (&$attempts, &$result, $proxy, $timeout): bool {
    $r = WrfClient::fetch($u, $useProxy ? $proxy : null, $ua, $t ?? $timeout);
    $challenge = WrfClient::isChallenge($r['body']);
    $attempts[] = ['route' => $route, 'status' => $r['status'], 'note' => $challenge ? 'challenge' : $r['note']];
    if ($result === null && $r['status'] === 200 && $r['body'] !== '' && !$challenge) {
        $result = ['route' => $route, 'status' => 200, 'bytes' => strlen($r['body']), 'body' => $r['body'], 'url' => $r['url']];
    }
    return $result !== null;
};

$lastNote = static function () use (&$attempts): string {
    return (string)end($attempts)['note'];
};

if (!$try('direct', $url)) {
    // Сетевая ошибка (DNS/connect/timeout, типично для блокировок из РФ) —
    // вариации на том же маршруте бессмысленны: сразу прокси.
    if (!WrfClient::isNetworkError($lastNote())) {
        if (!$try('direct-browser', $url, false, WrfClient::UA_BROWSER, min(12, $timeout))) {
            foreach (WrfClient::urlVariants($url) as $i => $v) {
                if ($try('direct-variant-' . ($i + 1), $v, false, WrfClient::UA_BROWSER, min(12, $timeout))) {
                    break;
                }
            }
        }
    }
    if ($result === null && $proxy !== null) {
        if (!$try('proxy', $url, true, WrfClient::UA_BROWSER)) {
            if (!WrfClient::isNetworkError($lastNote())) {
                foreach (WrfClient::urlVariants($url) as $i => $v) {
                    if ($try('proxy-variant-' . ($i + 1), $v, true, WrfClient::UA_BROWSER, min(12, $timeout))) {
                        break;
                    }
                }
            }
        }
    }
    if ($result === null && !isset($opt['no-wayback'])) {
        $snap = WrfClient::waybackSnapshot($url);
        if ($snap !== null) {
            if (!$try('wayback', $snap) && $proxy !== null) {
                $try('wayback-proxy', $snap, true);
            }
        } else {
            $attempts[] = ['route' => 'wayback', 'status' => null, 'note' => 'no snapshot'];
        }
    }
}

$out = null;
if ($result !== null) {
    $out = isset($opt['out']) ? (string)$opt['out'] : '/tmp/wrf_' . substr(md5($url), 0, 10) . '.html';
    file_put_contents($out, $result['body']);
}
WrfClient::emit([
    'url' => $url,
    'ok' => $result !== null,
    'route' => $result['route'] ?? null,
    'status' => $result['status'] ?? null,
    'bytes' => $result['bytes'] ?? 0,
    'out' => $out,
    'final_url' => $result['url'] ?? null,
    'proxy_available' => $proxy !== null,
    'attempts' => $attempts,
]);
exit($result !== null ? 0 : 1);