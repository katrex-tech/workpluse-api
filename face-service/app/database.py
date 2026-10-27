import os
import numpy as np
from typing import AsyncGenerator, List, Tuple, Optional, Dict, Any
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy import select, and_, func
from app.config import settings
from app.models import Base, FaceEnrollment, FaceAuditLog

# Ensure data and thumbnail directories exist
os.makedirs(os.path.dirname(settings.DATABASE_URL.replace("sqlite+aiosqlite:///", "")), exist_ok=True)
os.makedirs(settings.STORAGE_DIR, exist_ok=True)

# Async Engine setup
connect_args = {}
if settings.DATABASE_URL.startswith("sqlite"):
    connect_args = {"check_same_thread": False}

engine = create_async_engine(
    settings.DATABASE_URL,
    echo=settings.DEBUG,
    connect_args=connect_args,
    pool_pre_ping=True
)

AsyncSessionLocal = async_sessionmaker(
    bind=engine,
    class_=AsyncSession,
    expire_on_commit=False,
    autocommit=False,
    autoflush=False
)

async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with AsyncSessionLocal() as session:
        try:
            yield session
        finally:
            await session.close()

async def init_db():
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

async def search_gallery_exact(
    session: AsyncSession,
    tenant_id: str,
    probe_embedding: np.ndarray,
    target_user_id: Optional[str] = None
) -> List[Dict[str, Any]]:
    """
    Performs tenant-scoped exact cosine similarity search against enrolled gallery vectors.
    Since all stored embeddings and probe vectors are strictly L2-normalized:
      cosine_similarity(a, b) = np.dot(a, b)
    Returns sorted list of matches:
      [{ "user_id": ..., "score": ..., "thumbnail_path": ..., "enrolled_at": ... }]
    """
    stmt = select(FaceEnrollment).where(
        and_(
            FaceEnrollment.tenant_id == tenant_id,
            FaceEnrollment.is_active == True
        )
    )
    if target_user_id:
        stmt = stmt.where(FaceEnrollment.user_id == target_user_id)

    result = await session.execute(stmt)
    enrollments: List[FaceEnrollment] = result.scalars().all()

    if not enrollments:
        return []

    probe = np.asarray(probe_embedding, dtype=np.float32).flatten()
    probe_norm = np.linalg.norm(probe)
    if probe_norm > 0:
        probe = probe / probe_norm

    scored_candidates = []
    for enr in enrollments:
        emb = enr.embedding
        if not emb or len(emb) != settings.MODEL_EMBEDDING_DIM:
            continue
        vec = np.asarray(emb, dtype=np.float32).flatten()
        vec_norm = np.linalg.norm(vec)
        if vec_norm > 0:
            vec = vec / vec_norm
        
        # Exact cosine similarity dot product
        score = float(np.dot(probe, vec))
        # Clamp to [-1.0, 1.0]
        score = max(-1.0, min(1.0, score))

        scored_candidates.append({
            "user_id": enr.user_id,
            "score": score,
            "thumbnail_path": enr.thumbnail_path,
            "enrolled_at": enr.created_at.isoformat() if enr.created_at else None,
            "model_name": enr.model_name,
            "pipeline_version": enr.pipeline_version,
        })

    # Sort descending by similarity score
    scored_candidates.sort(key=lambda x: x["score"], reverse=True)
    return scored_candidates
