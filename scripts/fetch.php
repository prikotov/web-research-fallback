<?php

declare(strict_types=1);

/**
 * fetch.php — перебор способов доступа: direct → вариации → прокси → Wayback.
 *
 *   php scripts/fetch.php URL [--out FILE] [--proxy URL] [--timeout 20] [--no-wayback]
 *
 * JSON-отчёт {url, ok, method, status, bytes, out, proxy_available, attempts}.
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

$try = function (string $method, string $u, bool $useProxy = false, string $ua = WrfClient::UA, ?int $t = null, array $h = [], bool $chrome = false) use (&$attempts, &$result, $proxy, $timeout): bool {
    $r = $chrome ? WrfClient::chromeFetch($u, $useProxy ? $proxy : null, ($t ?? $timeout) * 1000) : WrfClient::fetch($u, $useProxy ? $proxy : null, $ua, $t ?? $timeout, null, $h);
    $challenge = WrfClient::isChallenge($r['body']);
    $shell = WrfClient::isJsShell($r['body']);
    $attempts[] = ['method' => $method, 'status' => $r['status'], 'note' => $challenge ? 'challenge' : ($shell ? 'js-shell' : $r['note'])];
    if ($result === null && $r['status'] === 200 && $r['body'] !== '' && !$challenge && !$shell) {
        $result = ['method' => $method, 'status' => 200, 'bytes' => strlen($r['body']), 'body' => $r['body'], 'url' => $r['url']];
    }
    return $result !== null;
};

$lastNote = static function () use (&$attempts): string {
    return (string)end($attempts)['note'];
};

if (!$try('direct', $url, false, WrfClient::UA, null)) {
    if (WrfClient::isTlsError($lastNote())) {
        // Сертификаты НУЦ Минцифры: доверие локальное, прокси не лечит.
        // Причина и официальная ссылка установки — в hint отчёта.
        $attempts[] = ['method' => 'proxy', 'status' => null, 'note' => 'skipped: TLS-доверие локальное, прокси не лечит'];
    } else {
        // Сетевая ошибка (DNS/connect/timeout, типично для блокировок из РФ) —
        // вариации на том же способе бессмысленны: сразу прокси.
        if (!WrfClient::isNetworkError($lastNote())) {
            if (!$try('direct-browser', $url, false, WrfClient::UA_BROWSER, min(12, $timeout), WrfClient::BROWSER_HEADERS)) {
                foreach (WrfClient::urlVariants($url) as $i => $v) {
                    if ($try('direct-variant-' . ($i + 1), $v, false, WrfClient::UA_BROWSER, min(12, $timeout), WrfClient::BROWSER_HEADERS)) {
                        break;
                    }
                }
            }
        }
        if ($result === null && $proxy !== null) {
            if (!$try('proxy', $url, true, WrfClient::UA_BROWSER, null, WrfClient::BROWSER_HEADERS)) {
                if (!WrfClient::isNetworkError($lastNote())) {
                    foreach (WrfClient::urlVariants($url) as $i => $v) {
                        if ($try('proxy-variant-' . ($i + 1), $v, true, WrfClient::UA_BROWSER, min(12, $timeout), WrfClient::BROWSER_HEADERS)) {
                            break;
                        }
                    }
                }
            }
        }
    }
    // Chrome-способ (приём news-web): JS-челлендж или JS-оболочка — рендерим браузером.
    if ($result === null && !isset($opt['no-chrome'])) {
        $needChrome = false;
        foreach ($attempts as $a) {
            if (in_array($a['note'], ['challenge', 'js-shell'], true)) {
                $needChrome = true;
                break;
            }
        }
        if ($needChrome) {
            // Chrome не умеет авторизованный прокси (--proxy-server без credentials),
            // поэтому chrome-proxy не пробуем.
            $try('chrome', $url, false, WrfClient::CHROME_UA, null, null, [], true);
        }
    }
    // Jina reader (r.jina.ai): рендерит JS, отдаёт markdown; из РФ только прокси.
    if ($result === null && $proxy !== null) {
        $jsSeen = false;
        foreach ($attempts as $a) {
            if (in_array($a['note'], ['challenge', 'js-shell'], true)) {
                $jsSeen = true;
                break;
            }
        }
        if ($jsSeen) {
            $jr = WrfClient::jinaReader($url, $proxy);
            if ($jr['body'] !== '') {
                $attempts[] = ['method' => 'jina', 'status' => 200, 'note' => 'reader markdown'];
                $result = ['method' => 'jina', 'status' => 200, 'bytes' => strlen($jr['body']), 'body' => $jr['body'], 'url' => $url];
            } else {
                $attempts[] = ['method' => 'jina', 'status' => $jr['status'], 'note' => $jr['note']];
            }
        }
    }
    // Feed-способ (опыт news-core): HTML закрыт, но RSS/Atom отдаётся.
    if ($result === null) {
        $feedUrl = isset($opt['feed']) ? (string)$opt['feed'] : WrfClient::feedFor($url);
        if ($feedUrl !== null) {
            $fi = WrfClient::feedItem($feedUrl, $url);
            if ($fi['item'] === null && $proxy !== null) {
                $fi = WrfClient::feedItem($feedUrl, $url, $proxy);
            }
            if ($fi['item'] !== null) {
                $attempts[] = ['method' => 'feed', 'status' => 200, 'note' => 'item: ' . $fi['item']['link']];
                $result = ['method' => 'feed', 'status' => 200, 'bytes' => strlen($fi['item']['body']), 'body' => $fi['item']['body'], 'url' => $fi['item']['feed']];
            } else {
                $attempts[] = ['method' => 'feed', 'status' => $fi['status'], 'note' => $fi['note']];
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
            $attempts[] = ['method' => 'wayback', 'status' => null, 'note' => 'no snapshot'];
        }
    }
}

$out = null;
if ($result !== null) {
    $out = isset($opt['out']) ? (string)$opt['out'] : '/tmp/wrf_' . substr(md5($url), 0, 10) . '.html';
    file_put_contents($out, $result['body']);
}
$hint = null;
foreach ($attempts as $a) {
    if (WrfClient::isTlsError((string)$a['note'])) {
        $hint = 'TLS-отказ: сертификат сайта выпущен НУЦ Минцифры и не входит в системный trust store. '
            . 'Установите корневой сертификат в систему с официальной страницы: ' . WrfClient::RU_CA_INSTALL_URL;
        break;
    }
}
WrfClient::emit([
    'url' => $url,
    'ok' => $result !== null,
    'method' => $result['method'] ?? null,
    'status' => $result['status'] ?? null,
    'bytes' => $result['bytes'] ?? 0,
    'out' => $out,
    'final_url' => $result['url'] ?? null,
    'proxy_available' => $proxy !== null,
    'hint' => $hint,
    'attempts' => $attempts,
]);
exit($result !== null ? 0 : 1);