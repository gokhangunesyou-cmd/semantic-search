"""Verify new index checkpoints and atomic alias activation on the isolated test stack."""
import subprocess
import uuid
from smoke_test import COMPOSE, ROOT, ID, SAMPLE, INDEX, request, command

temporary = 'products_reindex_test_' + uuid.uuid4().hex[:8]


def target_command(*args):
    result = subprocess.run(COMPOSE + ['exec', '-T', '-e', 'ELASTICSEARCH_INDEX=' + temporary, 'search-api', 'php', 'bin/console', *args], cwd=ROOT, text=True, capture_output=True)
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return result.stdout


target_command('app:elastic:init')
try:
    assert '1 kayıt' in target_command('app:products:index', '--activate')
    alias = request('GET', '/_alias/products_current', elastic=True)
    assert list(alias) == [temporary]
    result = request('POST', '/api/search', {'query': 'bebek arabası'})
    assert result['items'][0]['id'] == ID and result['items'][0]['document'] == SAMPLE
    assert '0 kayıt' in target_command('app:products:index')
finally:
    command('app:products:index', '--activate')
    request('DELETE', '/' + temporary, elastic=True)
assert list(request('GET', '/_alias/products_current', elastic=True)) == [INDEX]
print('PASS: new-index full rebuild, per-UUID checkpoint and atomic alias activation/rollback')
