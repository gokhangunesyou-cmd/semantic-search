"""Explicit setup command; normal API startup never downloads or converts a model."""
import os
from pathlib import Path
from huggingface_hub import snapshot_download

REVISION = '614241f622f53c4eeff9890bdc4f31cfecc418b3'

if __name__ == '__main__':
    root = Path(os.getenv('MODEL_DIR', '/models/e5-small'))
    snapshot_download(
        repo_id='intfloat/multilingual-e5-small', revision=REVISION, local_dir=root,
        allow_patterns=['config.json', 'tokenizer.json', 'tokenizer_config.json', 'special_tokens_map.json', 'sentencepiece.bpe.model', 'onnx/model.onnx'],
    )
    (root / 'revision.txt').write_text(REVISION + '\n')
    print(f'Model ready: {root} ({REVISION})')
