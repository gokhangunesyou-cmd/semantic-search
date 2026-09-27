import asyncio
import os
from contextlib import asynccontextmanager
from typing import Annotated, Literal

from fastapi import FastAPI, HTTPException, Query
from pydantic import BaseModel, Field, field_validator

from app.engine import Engine


class EmbeddingRequest(BaseModel):
    kind: Literal['query', 'document']
    texts: list[str] = Field(min_length=1, max_length=8, examples=[['tam yatan bebek arabası']])

    @field_validator('texts')
    @classmethod
    def valid_texts(cls, texts):
        if any(not text.strip() or len(text) > 20000 for text in texts):
            raise ValueError('Each text must contain 1–20000 characters')
        return texts


class EmbeddingItem(BaseModel):
    embedding: list[float] = Field(min_length=384, max_length=384)
    token_count: int
    truncated: bool


class EmbeddingResponse(BaseModel):
    model_version: str
    dimensions: Literal[384]
    items: list[EmbeddingItem]


def create_app(engine_factory=Engine):
    @asynccontextmanager
    async def lifespan(app):
        app.state.engine = await asyncio.to_thread(engine_factory)
        app.state.lock = asyncio.Lock()
        app.state.admitted = 0
        yield

    app = FastAPI(
        lifespan=lifespan,
        title='Embedding API',
        version='1.0.0',
        description='Türkçe ürün ve sorgu metinlerinden 384 boyutlu vektör üretir. API anahtarı gerekmez.',
        swagger_ui_parameters={'tryItOutEnabled': True},
    )

    @app.get('/health/live', tags=['Sağlık'])
    async def live():
        return {'status': 'ok'}

    @app.get('/health/ready', tags=['Sağlık'])
    async def ready():
        if not getattr(app.state, 'engine', None):
            raise HTTPException(503, 'Model not ready')
        return {'status': 'ready', 'model_version': os.getenv('EMBEDDING_VERSION', 'e5-small-onnx-fp32-v1')}

    @app.get('/v1/embeddings', tags=['Embedding'], response_model=EmbeddingResponse,
             responses={429: {'description': 'Embedding kapasitesi dolu; Retry-After ile yeniden deneyin.'}})
    async def embeddings_get(request: Annotated[EmbeddingRequest, Query()]):
        return await embeddings(request)

    # Internal batch indexing uses a body to avoid URL length limits.
    @app.post('/v1/embeddings', include_in_schema=False, response_model=EmbeddingResponse,
              responses={429: {'description': 'Embedding kapasitesi dolu; Retry-After ile yeniden deneyin.'}})
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
