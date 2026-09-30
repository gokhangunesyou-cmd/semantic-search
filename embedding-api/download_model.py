"""Download the configured embedding model into the persistent model volume."""
import os
from pathlib import Path
from huggingface_hub import snapshot_download

E5_MODEL = 'intfloat/multilingual-e5-small'
E5_REVISION = '614241f622f53c4eeff9890bdc4f31cfecc418b3'
TRENDYOL_MODEL = 'Trendyol/TY-ecomm-embed-multilingual-base-v1.2.0'
TRENDYOL_REVISION = '00c030c9a56bff9403f95c1b45f4b82e669e243c'


def model_revision(model_id):
    if model_id == E5_MODEL:
        return E5_REVISION
    if model_id == TRENDYOL_MODEL:
        return TRENDYOL_REVISION
    configured = os.getenv('EMBEDDING_REVISION', '').strip()
    if configured:
        return configured
    raise ValueError('Custom EMBEDDING_MODEL requires a pinned EMBEDDING_REVISION.')

if __name__ == '__main__':
    model_id = os.getenv('EMBEDDING_MODEL', E5_MODEL)
    revision = model_revision(model_id)
    folder = 'e5-small' if model_id == E5_MODEL else model_id.replace('/', '--')
    root = Path(os.getenv('MODEL_DIR', f'/models/{folder}'))
    options = {'repo_id': model_id, 'revision': revision, 'local_dir': root}
    if model_id == E5_MODEL:
        options['allow_patterns'] = [
            'config.json', 'tokenizer.json', 'tokenizer_config.json', 'special_tokens_map.json',
            'sentencepiece.bpe.model', 'onnx/model.onnx'
        ]
    snapshot_download(**options)
    (root / 'revision.txt').write_text(revision + '\n')
    print(f'Model ready: {model_id}@{revision} ({root})')
