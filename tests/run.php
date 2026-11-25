<?php
/**
 * Офлайн-тесты чистых функций WrfClient: сеть не трогают.
 * Запуск: php tests/run.php (из любого каталога). Код выхода 0/1.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/WrfClient.php';

$fail = 0;
$n = 0;
function check(bool $cond, string $label): void
{
    global $fail, $n;
    $n++;
    if (!$cond) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

// Детекторы отказов
check(WrfClient::isChallenge('<html>Just a moment…</html>'), 'challenge: just a moment');
check(WrfClient::isChallenge('<script src="https://servicepipe.tech/x.js"></script>'), 'challenge: servicepipe');
check(!WrfClient::isChallenge('<html><body>Статья о рынке акций.</body></html>'), 'challenge: нормальное тело');
check(WrfClient::isJsShell('<html><div id="root"></div></html>'), 'js-shell: div#root');
check(!WrfClient::isJsShell(str_repeat('x', 6000) . '<div id="root"></div>'), 'js-shell: большое тело не оболочка');
check(!WrfClient::isJsShell('<html><body>Статья.</body></html>'), 'js-shell: нормальное тело');
check(WrfClient::isNetworkError('curl errno 7'), 'network: errno 7');
check(WrfClient::isNetworkError('curl errno 28'), 'network: errno 28');
check(!WrfClient::isNetworkError('curl errno 60'), 'network: TLS не сетевая');
check(!WrfClient::isNetworkError('403'), 'network: HTTP не сетевая');
check(WrfClient::isTlsError('curl errno 60'), 'tls: errno 60');
check(WrfClient::isTlsError('curl errno 77'), 'tls: errno 77');
check(!WrfClient::isTlsError('curl errno 28'), 'tls: timeout не TLS');

// Вариации URL: только смена схемы, www не трогаем
check(WrfClient::urlVariants('https://x.ru/a') === ['http://x.ru/a'], 'variants: https→http');
check(WrfClient::urlVariants('http://x.ru/a') === ['https://x.ru/a'], 'variants: http→https');
check(WrfClient::urlVariants('ftp://x.ru/a') === [], 'variants: не http(s) — пусто');

// Конфиг и прокси: порядок разрешения
$tmp = sys_get_temp_dir() . '/wrf_test_' . getmypid();
mkdir($tmp);
$cfg = $tmp . '/.wrf_config.json';
file_put_contents($cfg, json_encode(['proxy' => 'http://cfg:1', 'brave_api_key' => ' k ']));
putenv('WRF_CONFIG=' . $cfg);
check(WrfClient::configPath() === $cfg, 'config: WRF_CONFIG уважается');
check(WrfClient::configKey('brave_api_key') === 'k', 'config: ключ trim');
check(WrfClient::configKey('tavily_api_key') === null, 'config: отсутствующий ключ null');
putenv('WEB_RESEARCH_PROXY=http://env:2');
check(WrfClient::loadProxy() === 'http://env:2', 'proxy: env поверх конфига');
check(WrfClient::loadProxy('http://exp:3') === 'http://exp:3', 'proxy: explicit поверх env');
putenv('WEB_RESEARCH_PROXY');
check(WrfClient::loadProxy() === 'http://cfg:1', 'proxy: конфиг без env');
unlink($cfg);
check(WrfClient::loadProxy() === null, 'proxy: нет нигде — null');
putenv('WRF_CONFIG');
check(WrfClient::configPath() === dirname(__DIR__) . '/.wrf_config.json', 'config: дефолт в корне репо');

// ensureConfigIgnored: не-git каталог, запись добавляется именем файла
$dir = $tmp . '/plain';
mkdir($dir);
$c2 = $dir . '/.wrf_config.json';
file_put_contents($c2, '{}');
check(WrfClient::ensureConfigIgnored($c2) === false, 'gitignore: первая попытка пополняет');
check(in_array('.wrf_config.json', array_map('trim', file($dir . '/.gitignore')), true), 'gitignore: запись именем файла');
check(WrfClient::ensureConfigIgnored($c2) === true, 'gitignore: повторно — уже закрыт');

// уборка
unlink($c2);
unlink($dir . '/.gitignore');
rmdir($dir);
rmdir($tmp);

if ($fail > 0) {
    fwrite(STDERR, "{$fail} из {$n} тестов упали\n");
    exit(1);
}
echo "OK: {$n} тестов\n";
