import os
import time
from fastapi import APIRouter, Depends, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import text
from app.config import settings
from app.database import get_db
from app.schemas import HealthStatus
from app.pipeline.engine import FaceEngine

router = APIRouter(tags=["Health"])

@router.get("/health/live", response_model=HealthStatus, status_code=status.HTTP_200_OK)
async def health_live():
    """Liveness probe indicating process is running."""
    return HealthStatus(
        status="ok",
        version=settings.PIPELINE_VERSION,
        model_loaded=FaceEngine._instance is not None,
        database_connected=True
    )

@router.get("/health/ready", response_model=HealthStatus, status_code=status.HTTP_200_OK)
async def health_ready(db: AsyncSession = Depends(get_db)):
    """Readiness probe verifying database connectivity and model initialization."""
    db_ok = False
    try:
        await db.execute(text("SELECT 1"))
        db_ok = True
    except Exception:
        db_ok = False

    engine = FaceEngine.get_instance()
    model_ok = engine is not None and engine.recognizer is not None

    overall_status = "ok" if (db_ok and model_ok) else "degraded"
    return HealthStatus(
        status=overall_status,
        version=settings.PIPELINE_VERSION,
        model_loaded=model_ok,
        database_connected=db_ok
    )
