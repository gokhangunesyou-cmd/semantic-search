import os
from pathlib import Path

import numpy as np
import onnxruntime as ort
from transformers import AutoTokenizer


def pool_and_normalize(hidden: np.ndarray, mask: np.ndarray) -> np.ndarray:
    expanded = mask[..., None].astype(np.float32)
    pooled = (hidden * expanded).sum(axis=1) / np.maximum(expanded.sum(axis=1), 1)
    return pooled / np.maximum(np.linalg.norm(pooled, axis=1, keepdims=True), 1e-12)


class Engine:
    def __init__(self):
        root = Path(os.getenv('MODEL_DIR', '/models/e5-small'))
        self.tokenizer = AutoTokenizer.from_pretrained(root, local_files_only=True)
        options = ort.SessionOptions()
        options.intra_op_num_threads = int(os.getenv('INFERENCE_THREADS', '2'))
        options.inter_op_num_threads = 1
        self.session = ort.InferenceSession(
            str(root / os.getenv('MODEL_FILE', 'onnx/model.onnx')),
            sess_options=options, providers=['CPUExecutionProvider'],
        )
        self.inputs = {item.name for item in self.session.get_inputs()}
        self.encode('query', ['hazırlık'])

    def encode(self, kind: str, texts: list[str]) -> list[dict]:
        prefix = 'query: ' if kind == 'query' else 'passage: '
        texts = [prefix + text.strip() for text in texts]
        lengths = [len(ids) for ids in self.tokenizer(texts, truncation=False)['input_ids']]
        # Bound padded tokens per inference to keep long eight-item requests
        # within the small server's memory budget. Preserve request ordering.
        step = max(1, min(8, 1024 // min(max(lengths), 512)))
        items = []
        for start in range(0, len(texts), step):
            chunk = texts[start:start + step]
            tokens = self.tokenizer(chunk, padding=True, truncation=True, max_length=512, return_tensors='np')
            # The pinned export declares token_type_ids; single-text segments use 0.
            if 'token_type_ids' in self.inputs and 'token_type_ids' not in tokens:
                tokens['token_type_ids'] = np.zeros_like(tokens['input_ids'])
            hidden = self.session.run(None, {key: value for key, value in tokens.items() if key in self.inputs})[0]
            vectors = pool_and_normalize(hidden, tokens['attention_mask'])
            if vectors.shape != (len(chunk), 384) or not np.isfinite(vectors).all():
                raise RuntimeError('Unexpected embedding output')
            items.extend(
                {'embedding': vector.tolist(), 'token_count': min(length, 512), 'truncated': length > 512}
                for vector, length in zip(vectors, lengths[start:start + step])
            )
        return items
