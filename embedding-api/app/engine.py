import os
from pathlib import Path

import numpy as np
import onnxruntime as ort
from transformers import AutoTokenizer

from .model_config import TRENDYOL_MODEL, embedding_version, model_id, model_revision, model_root


def pool_and_normalize(hidden: np.ndarray, mask: np.ndarray) -> np.ndarray:
    expanded = mask[..., None].astype(np.float32)
    pooled = (hidden * expanded).sum(axis=1) / np.maximum(expanded.sum(axis=1), 1)
    return pooled / np.maximum(np.linalg.norm(pooled, axis=1, keepdims=True), 1e-12)


class Engine:
    def __init__(self):
        self.model_id = model_id()
        self.version = embedding_version()
        self.root = model_root(self.model_id)
        revision_file = self.root / 'revision.txt'
        if not revision_file.is_file() or revision_file.read_text(encoding='utf-8').strip() != model_revision():
            raise RuntimeError('İndirilen model revision yapılandırmayla uyuşmuyor.')
        self.sentence_model = None
        self.session = None
        if self.model_id == 'intfloat/multilingual-e5-small':
            self._load_e5()
        else:
            self._load_sentence_transformer()
        self.dimensions = int(self.sentence_model.get_sentence_embedding_dimension()) if self.sentence_model else 384
        warmup = self.encode('query', ['hazırlık'])[0]['embedding']
        if len(warmup) != self.dimensions:
            raise RuntimeError('Embedding boyutu model yapılandırmasıyla uyuşmuyor.')

    def _load_e5(self):
        self.tokenizer = AutoTokenizer.from_pretrained(self.root, local_files_only=True)
        options = ort.SessionOptions()
        options.intra_op_num_threads = int(os.getenv('INFERENCE_THREADS', '2'))
        options.inter_op_num_threads = 1
        self.session = ort.InferenceSession(
            str(self.root / os.getenv('MODEL_FILE', 'onnx/model.onnx')),
            sess_options=options, providers=['CPUExecutionProvider'],
        )
        self.inputs = {item.name for item in self.session.get_inputs()}

    def _load_sentence_transformer(self):
        from sentence_transformers import SentenceTransformer

        self.sentence_model = SentenceTransformer(
            str(self.root), device='cpu', trust_remote_code=self.model_id == TRENDYOL_MODEL,
        )
        self.tokenizer = self.sentence_model[0].tokenizer

    def encode(self, kind: str, texts: list[str]) -> list[dict]:
        prefix = ''
        if self.sentence_model is None:
            prefix = 'query: ' if kind == 'query' else 'passage: '
        texts = [prefix + text.strip() for text in texts]
        max_length = 512 if self.sentence_model is None else self.sentence_model.max_seq_length
        lengths = [len(ids) for ids in self.tokenizer(texts, truncation=False)['input_ids']]
        # Bound padded tokens per inference to keep long eight-item requests
        # within the server's memory budget. Preserve request ordering.
        step = max(1, min(8, 1024 // min(max(lengths), max_length)))
        items = []
        for start in range(0, len(texts), step):
            chunk = texts[start:start + step]
            if self.sentence_model is None:
                tokens = self.tokenizer(
                    chunk, padding=True, truncation=True, max_length=max_length, return_tensors='np'
                )
                if 'token_type_ids' in self.inputs and 'token_type_ids' not in tokens:
                    tokens['token_type_ids'] = np.zeros_like(tokens['input_ids'])
                hidden = self.session.run(None, {key: value for key, value in tokens.items() if key in self.inputs})[0]
                vectors = pool_and_normalize(hidden, tokens['attention_mask'])
            else:
                vectors = self.sentence_model.encode(
                    chunk, batch_size=len(chunk), convert_to_numpy=True,
                    normalize_embeddings=True, show_progress_bar=False,
                )
            if vectors.shape != (len(chunk), self.dimensions) or not np.isfinite(vectors).all():
                raise RuntimeError('Unexpected embedding output')
            items.extend(
                {'embedding': vector.tolist(), 'token_count': min(length, max_length), 'truncated': length > max_length}
                for vector, length in zip(vectors, lengths[start:start + step])
            )
        return items
