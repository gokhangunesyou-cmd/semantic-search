CREATE TABLE IF NOT EXISTS products (
    id TEXT PRIMARY KEY,
    document JSONB NOT NULL CHECK (jsonb_typeof(document) = 'object'),
    revision BIGINT NOT NULL DEFAULT 1,
    deleted BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (document ? 'id' AND document->>'id' = id AND id <> ''),
    CHECK (document ? 'variants' AND jsonb_typeof(document->'variants') = 'array'),
    CHECK (NOT (document ? 'semantic'))
);
CREATE TABLE IF NOT EXISTS product_index_state (
    product_id TEXT NOT NULL REFERENCES products(id),
    index_uuid TEXT NOT NULL,
    indexed_revision BIGINT NOT NULL,
    embedding_hash TEXT,
    semantic JSONB,
    PRIMARY KEY (product_id, index_uuid)
);
