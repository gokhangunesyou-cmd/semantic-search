import asyncio
import os
from contextlib import asynccontextmanager
from typing import Literal

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field, field_validator

from app.engine import Engine


class EmbeddingRequest(BaseModel):
    kind: Literal['query', 'document']
    texts: list[str] = Field(min_length=1, max_length=8)

    @field_validator('texts')
    @classmethod
    def valid_texts(cls, texts):
        if any(not text.strip() or len(text) > 20000 for text in texts):
            raise ValueError('Each text must contain 1–20000 characters')
        return texts


def create_app(engine_factory=Engine):
    @asynccontextmanager
    async def lifespan(app):
        app.state.engine = await asyncio.to_thread(engine_factory)
        app.state.lock = asyncio.Lock()
        app.state.admitted = 0
        yield

    app = FastAPI(lifespan=lifespan)

    @app.get('/health/live')
    async def live():
        return {'status': 'ok'}

    @app.get('/health/ready')
    async def ready():
        if not getattr(app.state, 'engine', None):
            raise HTTPException(503, 'Model not ready')
        return {'status': 'ready', 'model_version': os.getenv('EMBEDDING_VERSION', 'e5-small-onnx-fp32-v1')}

    @app.post('/v1/embeddings')
    async def embeddings(request: EmbeddingRequest):
        if app.state.admitted >= 4:
            raise HTTPException(429, 'Embedding capacity reached', headers={'Retry-After': '1'})
        app.state.admitted += 1
        try:
            async with app.state.lock:
                # Keep the lock until the CPU job actually finishes even on cancellation.
                task = asyncio.create_task(asyncio.to_thread(app.state.engine.encode, request.kind, request.texts))
                try:
                    items = await asyncio.shield(task)
                except asyncio.CancelledError:
                    await task
                    raise
            return {'model_version': os.getenv('EMBEDDING_VERSION', 'e5-small-onnx-fp32-v1'), 'dimensions': 384, 'items': items}
        finally:
            app.state.admitted -= 1

    return app


app = create_app()
