"""Optional CPU INT8 experiment. Use a NEW EMBEDDING_VERSION and NEW index afterward."""
import os
from pathlib import Path
from onnxruntime.quantization import QuantType, quantize_dynamic

if __name__ == '__main__':
    root = Path(os.getenv('MODEL_DIR', '/models/e5-small')) / 'onnx'
    quantize_dynamic(str(root / 'model.onnx'), str(root / 'model.int8.onnx'), weight_type=QuantType.QInt8, per_channel=False)
