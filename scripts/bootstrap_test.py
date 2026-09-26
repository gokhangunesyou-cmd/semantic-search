"""Regression: boot the API against an existing DB without product tables.

Requires the local Compose services/images. Uses only a new temporary DB/container;
the existing product database and volumes are not modified.
"""
import pathlib
import subprocess
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[1]
COMPOSE = ['docker', 'compose']
suffix = uuid.uuid4().hex[:10]
database = 'bootstrap_test_' + suffix
container = 'semantic-bootstrap-test-' + suffix


def run(args, **kwargs):
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, **kwargs)
    if result.returncode:
        raise RuntimeError(result.stderr + result.stdout)
    return result.stdout.strip()


def sql(statement, db=None):
    return run(COMPOSE + ['exec', '-T', 'postgres', 'sh', '-c',
        'exec psql -U "$POSTGRES_USER" -d "$1" -v ON_ERROR_STOP=1 -At',
        'sh', db or 'postgres'], input=statement)


def ready():
    for _ in range(40):
        result = subprocess.run(['docker', 'exec', container, 'php', '-r',
            'exit(@file_get_contents("http://localhost/health/ready") === false ? 1 : 0);'],
            text=True, capture_output=True)
        if result.returncode == 0:
            return
        time.sleep(0.5)
    raise RuntimeError(run(['docker', 'logs', container]))


sql('CREATE DATABASE ' + database)
created = False
try:
    assert sql("SELECT to_regclass('public.products') IS NULL", database) == 't'
    run(COMPOSE + ['run', '--no-deps', '-d', '--name', container,
        '-e', f'DATABASE_DSN=pgsql:host=postgres;port=5432;dbname={database}', 'search-api'])
    created = True
    ready()
    assert sql("SELECT to_regclass('public.products'),to_regclass('public.product_index_state')", database) == 'products|product_index_state'
    sql("INSERT INTO products(id,document) VALUES ('bootstrap-check','{\"id\":\"bootstrap-check\",\"variants\":[]}')", database)
    run(['docker', 'restart', container])
    ready()
    assert sql('SELECT count(*) FROM products', database) == '1'
    print('PASS: missing schema created automatically; HTTP ready; restart preserves data.')
finally:
    if created:
        run(['docker', 'rm', '-f', container])
    sql('DROP DATABASE ' + database + ' WITH (FORCE)')
