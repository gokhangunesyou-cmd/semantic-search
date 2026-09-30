import os
from pathlib import Path

E5_MODEL = 'intfloat/multilingual-e5-small'
E5_REVISION = '614241f622f53c4eeff9890bdc4f31cfecc418b3'
TRENDYOL_MODEL = 'Trendyol/TY-ecomm-embed-multilingual-base-v1.2.0'
TRENDYOL_REVISION = '00c030c9a56bff9403f95c1b45f4b82e669e243c'


def model_id():
    return os.getenv('EMBEDDING_MODEL', E5_MODEL)


def model_revision():
    selected = model_id()
    if selected == E5_MODEL:
        return E5_REVISION
    if selected == TRENDYOL_MODEL:
        return TRENDYOL_REVISION
    configured = os.getenv('EMBEDDING_REVISION', '').strip()
    if configured:
        return configured
    raise RuntimeError('Custom EMBEDDING_MODEL requires a pinned EMBEDDING_REVISION.')


def embedding_version():
    selected = model_id()
    model_file = os.getenv('MODEL_FILE', 'onnx/model.onnx')
    if selected == E5_MODEL and model_revision() == E5_REVISION and model_file == 'onnx/model.onnx':
        return 'e5-small-onnx-fp32-v1'
    suffix = f'#{model_file}' if selected == E5_MODEL else ''
    return f'{selected}@{model_revision()}{suffix}'


def model_root(selected=None):
    selected = selected or model_id()
    folder = 'e5-small' if selected == E5_MODEL else selected.replace('/', '--')
    return Path(os.getenv('MODEL_DIR', f'/models/{folder}'))