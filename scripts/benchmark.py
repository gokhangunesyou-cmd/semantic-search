"""Local PoC concurrency measurement; this does not assess search relevance."""
import argparse
import concurrent.futures
import json
import math
import os
import pathlib
import time
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--url', default='http://127.0.0.1:18080')
parser.add_argument('--requests', type=int, default=30)
parser.add_argument('--concurrency', type=int, default=3)
args = parser.parse_args()
if args.requests < 1 or not 1 <= args.concurrency <= 3:
    parser.error('requests >= 1 and concurrency 1–3 required')
path = pathlib.Path(__file__).resolve().parents[1] / '.env'
env = dict(line.split('=', 1) for line in path.read_text().splitlines() if line and not line.startswith('#')) if path.exists() else {}
key = os.getenv('SEARCH_API_KEY') or env['SEARCH_API_KEY']


def run(i):
    query = ['tam yatan bebek arabası', 'gri baston puset', 'Moonybaby MB111'][i % 3]
    req = urllib.request.Request(args.url.rstrip('/') + '/api/search', data=json.dumps({'query': query, 'mode': 'semantic'}).encode(), headers={'Content-Type': 'application/json', 'X-API-Key': key})
    started = time.perf_counter()
    with urllib.request.urlopen(req, timeout=180) as response:
        result = json.load(response)
        if not result.get('items'):
            raise RuntimeError('Load test requires indexed sample products')
    return time.perf_counter() - started


run(0)
with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
    times = sorted(pool.map(run, range(args.requests)))
print(json.dumps({'requests': len(times), 'concurrency': args.concurrency, 'p50_seconds': round(times[math.ceil(len(times) * .5) - 1], 3), 'p95_seconds': round(times[math.ceil(len(times) * .95) - 1], 3), 'max_seconds': round(max(times), 3)}, indent=2))
