"""Общие хелперы web-research-fallback: прокси из рантайма, fetch, детект challenge.

Секреты не выводятся: load_proxy возвращает значение только в память,
все отчёты содержат лишь имена маршрутов (direct/proxy/wayback).
"""
import json
import os
import re
import subprocess
import sys
import urllib.request

UA_DEFAULT = 'Mozilla/5.0 (research; web-research-fallback)'
UA_BROWSER = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
              '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36')
PROXY_KEYS = ('WEB_RESEARCH_PROXY',)
CONFIG_NAME = '.wrf_config.json'
CHALLENGE_MARKERS = ('just a moment', 'client challenge', 'checking your browser',
                     'enable javascript and cookies', 'access denied')


def config_path():
    """Свой конфиг скилла: wrf_config.json в корне репо (или путь из WRF_CONFIG)."""
    return os.environ.get('WRF_CONFIG') or os.path.join(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))), CONFIG_NAME)


def ensure_config_ignored(path=None):
    """Конфиг с секретом должен быть в .gitignore того репо/каталога, где лежит.

    Как в Ga4Client.php (google-analytics-core): если каталог конфига внутри
    git-репо — проверяем git check-ignore и пополняем корневой .gitignore;
    если репо нет — гарантируем .gitignore в каталоге конфига. Пишем только
    имя файла, не значение; уведомление — в stderr.
    """
    p = os.path.realpath(path or config_path())
    directory = os.path.dirname(p)
    entry = os.path.basename(p)
    top = None
    r = subprocess.run(['git', '-C', directory, 'rev-parse', '--show-toplevel'],
                       capture_output=True, text=True)
    if r.returncode == 0:
        top = r.stdout.strip()
    if top:
        rel = os.path.relpath(p, top)
        chk = subprocess.run(['git', '-C', top, 'check-ignore', '-q', '--', rel],
                             capture_output=True)
        if chk.returncode == 0:
            return True
        gi = os.path.join(top, '.gitignore')
    else:
        gi = os.path.join(directory, '.gitignore')
    lines = []
    if os.path.exists(gi):
        lines = [l.strip() for l in open(gi, encoding='utf-8', errors='replace')]
    if entry in lines:
        return True
    with open(gi, 'a', encoding='utf-8') as f:
        if lines and lines[-1] != '':
            f.write('\n')
        f.write(entry + '\n')
    print(f'⚠️  .gitignore пополнен записью {entry}: {gi}', file=sys.stderr)
    return False


def load_proxy(explicit=None):
    """Прокси: --proxy → env WEB_RESEARCH_PROXY → свой конфиг скилла. None если нет."""
    if explicit:
        return explicit
    for k in PROXY_KEYS:
        v = os.environ.get(k)
        if v:
            return v.strip().strip('"').strip("'")
    p = config_path()
    if os.path.exists(p):
        try:
            v = json.load(open(p, encoding='utf-8')).get('proxy') or ''
        except Exception:
            v = ''
        v = v.strip().strip('"').strip("'")
        if v:
            return v
    return None


def fetch(url, proxy=None, ua=UA_DEFAULT, timeout=45, data=None, headers=None):
    """GET/POST; возвращает (body: bytes, status: int, note: str). Без исключений наружу.

    С прокси — curl (умеет http/https/socks-прокси и CONNECT over TLS);
    напрямую — urllib. Прокси в stdout/логи не выводится.
    """
    hdrs = {'User-Agent': ua}
    if headers:
        hdrs.update(headers)
    if data is not None and 'Content-Type' not in hdrs:
        hdrs['Content-Type'] = 'application/x-www-form-urlencoded'
    if proxy:
        cmd = ['curl', '-sL', '-m', str(timeout), '--proxy', proxy, '-A', ua]
        for k, v in hdrs.items():
            if k != 'User-Agent':
                cmd += ['-H', f'{k}: {v}']
        if data is not None:
            cmd += ['--data-binary', data if isinstance(data, bytes) else data.encode()]
        cmd += ['-w', '\n%{http_code}', url]
        p = subprocess.run(cmd, capture_output=True)
        out = p.stdout
        body, _, code = out.rpartition(b'\n')
        try:
            st = int(code)
        except ValueError:
            st = 0
        return body, st, ('' if st else f'curl exit {p.returncode}')
    req = urllib.request.Request(url, data=data, headers=hdrs)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.read(), r.status, ''
    except urllib.error.HTTPError as e:
        return e.read(), e.code, f'http {e.code}'
    except Exception as e:
        return b'', 0, type(e).__name__ + ': ' + str(e)[:120]


def is_challenge(body):
    head = body[:4000].decode('utf-8', 'replace').lower()
    return any(m in head for m in CHALLENGE_MARKERS)


def url_variants(url):
    """http/https и www/без www — для повторных прямых попыток."""
    out = []
    m = re.match(r'(https?)://(www\.)?(.*)$', url)
    if not m:
        return out
    scheme, www, rest = m.groups()
    other = 'http' if scheme == 'https' else 'https'
    out.append(f'{other}://{www or ""}{rest}')
    out.append(f'{scheme}://{"www." if not www else ""}{rest}')
    out.append(f'{other}://{"www." if not www else ""}{rest}')
    return out