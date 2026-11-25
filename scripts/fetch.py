#!/usr/bin/env python3
"""fetch.py — цепочка доступа к недоступному URL: direct → вариации → прокси → Wayback.

  python3 scripts/fetch.py URL [--out FILE] [--proxy URL] [--timeout 45] [--no-wayback]

Печатает JSON-отчёт {url, ok, route, status, bytes, out, attempts:[{route,status,note}]}.
Секреты в отчёт не попадают: только имена маршрутов. Тело — в --out или /tmp.
Код выхода 0 если какой-то маршрут дал 200 без JS-challenge, иначе 1.
"""
import argparse
import hashlib
import json
import os
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as C  # noqa: E402


def wayback_snapshot(url):
    body, st, _ = C.fetch('http://archive.org/wayback/available?url=' + urllib.parse.quote(url, safe=''))
    if st != 200:
        return None
    try:
        d = json.loads(body)
        snap = d.get('archived_snapshots', {}).get('closest', {})
        if snap.get('available'):
            return snap['url']
    except Exception:
        pass
    return None


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('url')
    ap.add_argument('--out')
    ap.add_argument('--proxy')
    ap.add_argument('--timeout', type=int, default=20)
    ap.add_argument('--no-wayback', action='store_true')
    args = ap.parse_args()
    proxy = C.load_proxy(args.proxy)
    attempts = []
    result = None

    def try_route(route, url, use_proxy=False, ua=C.UA_DEFAULT, timeout=None):
        nonlocal result
        body, st, note = C.fetch(url, proxy=proxy if use_proxy else None, ua=ua,
                                 timeout=timeout or args.timeout)
        challenge = C.is_challenge(body)
        attempts.append({'route': route, 'status': st, 'note': ('challenge' if challenge else note)})
        if st == 200 and body and not challenge and result is None:
            result = {'route': route, 'status': st, 'bytes': len(body), 'body': body}
        return result is not None

    if try_route('direct', args.url):
        pass
    else:
        for i, v in enumerate(C.url_variants(args.url)):
            if try_route(f'direct-variant-{i+1}', v, ua=C.UA_BROWSER, timeout=min(12, args.timeout)):
                break
        if result is None and proxy:
            if not try_route('proxy', args.url, use_proxy=True):
                for i, v in enumerate(C.url_variants(args.url)):
                    if try_route(f'proxy-variant-{i+1}', v, use_proxy=True, ua=C.UA_BROWSER, timeout=min(12, args.timeout)):
                        break
        if result is None and not args.no_wayback:
            snap = wayback_snapshot(args.url)
            if snap:
                if not try_route('wayback', snap):
                    if proxy:
                        try_route('wayback-proxy', snap, use_proxy=True)
            else:
                attempts.append({'route': 'wayback', 'status': None, 'note': 'no snapshot'})

    out = args.out
    if result:
        if not out:
            out = '/tmp/wrf_' + hashlib.md5(args.url.encode()).hexdigest()[:10] + '.html'
        open(out, 'wb').write(result['body'])
    report = {'url': args.url, 'ok': result is not None,
              'route': result['route'] if result else None,
              'status': result['status'] if result else None,
              'bytes': result['bytes'] if result else 0,
              'out': out if result else None,
              'proxy_available': proxy is not None,
              'attempts': attempts}
    print(json.dumps(report, ensure_ascii=False, indent=1))
    sys.exit(0 if result else 1)


if __name__ == '__main__':
    main()