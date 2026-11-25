#!/usr/bin/env python3
"""search.py — поиск источников через lite.duckduckgo.com (POST q=), direct → прокси.

  python3 scripts/search.py "запрос" [--limit 10] [--proxy URL]

Печатает JSON-список {title, url, snippet}. html.duckduckgo.com/html/ GET не
используем (пустой ответ из сети владельца). Секреты не выводятся.
"""
import argparse
import json
import os
import re
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as C  # noqa: E402


def ddg_lite(query, proxy=None, limit=10):
    data = urllib.parse.urlencode({'q': query}).encode()
    body, st, note = C.fetch('https://lite.duckduckgo.com/lite/', proxy=proxy, data=data)
    if st != 200 or not body:
        return None, st, note
    html = body.decode('utf-8', 'replace')
    out = []
    for m in re.finditer(r'<a(?=[^>]*class=["\']result-link["\'])[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>', html, re.S):
        url = m.group(1)
        if url.startswith('//'):
            url = 'https:' + url
        title = re.sub(r'<[^>]+>', '', m.group(2))
        out.append({'title': ' '.join(title.split()), 'url': url})
    snips = re.findall(r'class=["\']result-snippet["\'][^>]*>(.*?)</td>', html, re.S)
    for i, s in enumerate(snips[:len(out)]):
        out[i]['snippet'] = ' '.join(re.sub(r'<[^>]+>', '', s).split())[:300]
    return out[:limit], st, ''


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('query')
    ap.add_argument('--limit', type=int, default=10)
    ap.add_argument('--proxy')
    args = ap.parse_args()
    proxy = C.load_proxy(args.proxy)
    res, st, note = ddg_lite(args.query, None, args.limit)
    route = 'direct'
    if res is None and proxy:
        res, st, note = ddg_lite(args.query, proxy, args.limit)
        route = 'proxy'
    print(json.dumps({'query': args.query, 'route': route if res is not None else None,
                      'status': st, 'note': note, 'results': res or []}, ensure_ascii=False, indent=1))
    sys.exit(0 if res else 1)


if __name__ == '__main__':
    main()