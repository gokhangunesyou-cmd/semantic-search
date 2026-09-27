import numpy as np
from fastapi.testclient import TestClient
from app.engine import pool_and_normalize
from app.main import create_app


class FakeEngine:
    def encode(self, kind, texts):
        return [{'embedding': [1.0] + [0.0] * 383, 'token_count': 4, 'truncated': False} for _ in texts]


def test_masked_pooling_ignores_padding_and_normalizes():
    hidden = np.array([[[3., 0.], [0., 4.], [999., 999.]]])
    output = pool_and_normalize(hidden, np.array([[1, 1, 0]]))
    np.testing.assert_allclose(output, [[0.6, 0.8]])


def test_contract_and_input_limits():
    with TestClient(create_app(FakeEngine)) as client:
        result = client.post('/v1/embeddings', json={'kind': 'document', 'texts': ['Türkçe ürün']})
        assert result.status_code == 200
        assert result.json()['dimensions'] == 384
        assert len(result.json()['items'][0]['embedding']) == 384
        for payload in [
            {'kind': 'wrong', 'texts': ['x']},
            {'kind': 'query', 'texts': []},
            {'kind': 'query', 'texts': [' ']},
            {'kind': 'query', 'texts': ['x'] * 9},
        ]:
            assert client.post('/v1/embeddings', json=payload).status_code == 422


def test_browser_get_contract_and_validation():
    with TestClient(create_app(FakeEngine)) as client:
        response = client.get('/v1/embeddings', params=[('kind', 'query'), ('texts', 'Türkçe ürün & test'), ('texts', 'ikinci')])
        assert response.status_code == 200
        assert len(response.json()['items']) == 2
        for params in [
            {}, {'kind': 'wrong', 'texts': 'x'}, {'kind': 'query'},
            {'kind': 'query', 'texts': ' '}, {'kind': 'query', 'texts': ['x'] * 9},
            {'kind': 'query', 'texts': 'x' * 20001},
        ]:
            assert client.get('/v1/embeddings', params=params).status_code == 422
        operation = client.get('/openapi.json').json()['paths']['/v1/embeddings']
        assert 'get' in operation and 'post' not in operation
        assert 'requestBody' not in operation['get']


def test_real_model_prefix_truncation_and_norm():
    import os
    import pytest
    if not os.path.isfile(os.path.join(os.getenv('MODEL_DIR', '/models/e5-small'), 'onnx/model.onnx')):
        pytest.skip('Run with the downloaded model volume for real inference test')
    from app.engine import Engine
    engine = Engine()
    short = engine.encode('query', ['bebek arabası'])[0]
    long = engine.encode('document', ['bebek arabası ' * 1000])[0]
    assert len(short['embedding']) == 384
    assert abs(np.linalg.norm(short['embedding']) - 1) < 1e-5
    assert not short['truncated']
    assert long['truncated'] and long['token_count'] == 512
    batch = engine.encode('document', ['bebek arabası ' * 1000] * 8)
    assert len(batch) == 8
    assert all(item['truncated'] and item['token_count'] == 512 for item in batch)
    for item in batch:
        np.testing.assert_allclose(item['embedding'], long['embedding'], rtol=1e-4, atol=1e-5)
