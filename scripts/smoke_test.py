"""Destructive ONLY to the bundled sample ID. Run on the isolated local PoC stack."""
import base64
import copy
import json
import math
import pathlib
import subprocess
import time
import urllib.error
import urllib.request
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[1]
ENV = dict(line.split('=', 1) for line in (ROOT / '.env').read_text().splitlines() if line and not line.startswith('#'))
COMPOSE = ['docker', 'compose', '-f', 'compose.yaml', '-f', 'compose.test.yaml']
INDEX = ENV.get('ELASTICSEARCH_INDEX', 'products_v1')
SAMPLE = json.loads((ROOT / 'search-api/tests/fixtures/product.json').read_text())['_source']
ID = str(SAMPLE['id'])


def request(method, path, body=None, elastic=False, expected=200):
    if method == 'GET' and body is not None:
        path += '?' + urllib.parse.urlencode(body)
        body = None
    headers = {'Content-Type': 'application/json'}
    if elastic:
        base = 'http://127.0.0.1:19200'
        token = base64.b64encode(('elastic:' + ENV['ELASTICSEARCH_PASSWORD']).encode()).decode()
        headers['Authorization'] = 'Basic ' + token
    else:
        base = 'http://127.0.0.1:18080'
    req = urllib.request.Request(base + path, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=180) as response:
            status, raw = response.status, response.read()
    except urllib.error.HTTPError as error:
        status, raw = error.code, error.read()
    assert status == expected, (path, status, raw[:1500])
    return json.loads(raw) if raw else None


def command(*args, success=True):
    result = subprocess.run(COMPOSE + ['exec', '-T', 'search-api', 'php', 'bin/console', *args], cwd=ROOT, text=True, capture_output=True)
    print(result.stdout.strip())
    if success and result.returncode:
        raise AssertionError(result.stderr)
    if not success:
        assert result.returncode != 0, 'Expected command failure'
    return result.stdout


def esdoc():
    return request('GET', f'/{INDEX}/_doc/{ID}', elastic=True)['_source']


def main():
    command('app:db:init')
    # Refuse to run against an index with unrelated user documents.
    indices = request('GET', '/_cat/indices?format=json', elastic=True)
    if any(row['index'] == INDEX for row in indices):
        hits = request('POST', f'/{INDEX}/_search', {'size': 2, 'query': {'bool': {'must_not': [{'ids': {'values': [ID]}}]}}}, elastic=True)
        assert hits['hits']['total']['value'] == 0, 'Use a dedicated empty PoC index'
    else:
        command('app:elastic:init')
    command('app:products:import', 'tests/fixtures/product.json')
    dbdoc = request('GET', f'/api/documents/{ID}')['document']
    assert dbdoc == SAMPLE, 'DB must preserve every sample field and array'
    command('app:products:index')
    indexed = esdoc()
    vector = indexed.pop('semantic')['vector']
    assert indexed == SAMPLE, 'Elasticsearch must preserve the original root document'
    assert len(vector) == 384 and abs(math.sqrt(sum(v*v for v in vector)) - 1) < 1e-5
    before = esdoc()['semantic']
    assert '0 kayıt' in command('app:products:index'), 'Second run must skip unchanged rows'
    results = request('GET', '/api/search', {'query': 'tam yatan bebek arabası', 'brand_id': '20048005', 'category_ids': '11'})
    assert len(results['items']) == 1 and results['items'][0]['document'] == SAMPLE
    assert request('GET', '/api/search', {'query': 'bebek arabası', 'brand_id': 'missing'})['count'] == 0
    print('PASS: DB round-trip, original ES _source, real embeddings, idempotency, filters, semantic results')

    changed = copy.deepcopy(SAMPLE)
    changed['variants'][0]['merchants'][0]['price'] = 1234.5
    request('PUT', f'/api/documents/{ID}', changed)
    subprocess.run(COMPOSE + ['stop', 'embedding'], cwd=ROOT, check=True, capture_output=True)
    try:
        command('app:products:index')
        current = esdoc()
        assert current['variants'][0]['merchants'][0]['price'] == 1234.5
        assert current['semantic']['hash'] == before['hash'] and current['semantic']['vector'] == before['vector']
        # Changed semantic text must fail safely while inference is unavailable.
        changed['variants'][0]['name'] += ' Kırmızı'
        request('PUT', f'/api/documents/{ID}', changed)
        command('app:products:index', success=False)
        assert esdoc() == current, 'Failed inference must preserve the last indexed document'
        request('GET', '/api/search', {'query': 'bebek arabası'}, expected=503)
    finally:
        subprocess.run(COMPOSE + ['start', 'embedding'], cwd=ROOT, check=True, capture_output=True)
    for _ in range(60):
        try:
            with urllib.request.urlopen('http://127.0.0.1:18000/health/ready', timeout=2): break
        except (OSError, urllib.error.URLError): time.sleep(1)
    command('app:products:index')
    assert esdoc()['semantic']['hash'] != before['hash']
    print('PASS: cached vector update without Python, failed-inference preservation, resumed indexing')

    # Two variants; two merchants in one variant. Conditions must not cross objects.
    nested = copy.deepcopy(SAMPLE)
    nested['unknown_future_object'] = {}
    nested['unknown_future_list'] = []
    second = copy.deepcopy(nested['variants'][0])
    second['id'] = 999001
    second['name'] = 'Farklı kırmızı bebek arabası'
    second['merchants'][0]['merchant'] = 999
    second['merchants'][0]['price'] = 10
    nested['variants'].append(second)
    extra = copy.deepcopy(nested['variants'][0]['merchants'][0])
    extra['merchant'] = 777
    extra['price'] = 20
    nested['variants'][0]['merchants'].append(extra)
    request('PUT', f'/api/documents/{ID}', nested)
    command('app:products:index')
    query = {'query': {'nested': {'path': 'variants', 'query': {'bool': {'must': [
        {'term': {'variants.id': '470960'}},
        {'nested': {'path': 'variants.merchants', 'query': {'term': {'variants.merchants.merchant': '999'}}}},
    ]}}}}}
    assert request('POST', f'/{INDEX}/_search', query, elastic=True)['hits']['total']['value'] == 0
    query['query']['nested']['query']['bool']['must'][1]['nested']['query'] = {'bool': {'must': [
        {'term': {'variants.merchants.merchant': '618'}}, {'term': {'variants.merchants.price': 20}},
    ]}}
    assert request('POST', f'/{INDEX}/_search', query, elastic=True)['hits']['total']['value'] == 0
    result = request('GET', '/api/search', {'query': 'bebek arabası'})
    assert result['count'] == 1 and len(result['items'][0]['document']['variants']) == 2
    assert result['items'][0]['document']['unknown_future_object'] == {}
    assert result['items'][0]['document']['unknown_future_list'] == []
    print('PASS: nested variant/merchant isolation and one result per parent document')

    # Mapping error must not advance the checkpoint or overwrite the last good document.
    bad = copy.deepcopy(nested)
    bad['variants'][0]['merchants'][0]['price'] = 'not-a-number'
    request('PUT', f'/api/documents/{ID}', bad)
    command('app:products:index', success=False)
    assert esdoc()['variants'][0]['merchants'][0]['price'] != 'not-a-number'
    request('PUT', f'/api/documents/{ID}', SAMPLE)
    command('app:products:index')
    request('DELETE', f'/api/documents/{ID}')
    command('app:products:index')
    assert request('GET', '/api/search', {'query': 'bebek arabası'})['count'] == 0
    command('app:products:import', 'tests/fixtures/product.json')
    command('app:products:index')
    original = esdoc(); original.pop('semantic')
    assert original == SAMPLE
    print('PASS: failed-bulk recovery, DB tombstone deletion, restore original sample')


if __name__ == '__main__':
    main()
