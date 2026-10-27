import os
import sys
import time
from contextlib import asynccontextmanager
import psutil
from fastapi import FastAPI, Request, HTTPException, status
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware

from app.config import settings
from app.database import init_db
from app.pipeline.engine import FaceEngine
from app.pipeline.quality import QualityException
from app.api import health, detect, enroll, gallery, identify

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup:
    print(f"[*] Starting {settings.SERVICE_NAME} v{settings.PIPELINE_VERSION}...")
    
    # 1. Initialize DB tables
    await init_db()
    print("[*] Database tables verified.")

    # 2. Warm up Face Engine (Detection & Recognition models)
    engine = FaceEngine.get_instance()
    proc = psutil.Process()
    mem_mb = proc.memory_info().rss / (1024 * 1024)
    print(f"[*] Deep Face Engine loaded. Weights checksum: {engine.recognizer.checksum[:16]}... Memory RSS: {mem_mb:.1f} MB")
    
    yield
    
    # Shutdown:
    print("[*] Shutting down Face Engine worker thread pools...")
    engine.thread_pool.shutdown(wait=False)

app = FastAPI(
    title=settings.SERVICE_NAME,
    version=settings.PIPELINE_VERSION,
    description="Production-grade Face Detection, Alignment, and ArcFace 512-D Recognition Backend.",
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
    lifespan=lifespan
)

# CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Request Timing & Security Middleware (Never logs image bytes, tokens, or embeddings)
@app.middleware("http")
async def add_process_time_and_security(request: Request, call_next):
    start_time = time.perf_counter()
    response = await call_next(request)
    process_time = (time.perf_counter() - start_time) * 1000
    response.headers["X-Process-Time-Ms"] = f"{process_time:.2f}"
    return response

# Exception Handlers
@app.exception_handler(QualityException)
async def quality_exception_handler(request: Request, exc: QualityException):
    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        content={
            "error": {
                "code": exc.code,
                "message": exc.message,
                "actionable_remedy": exc.actionable_remedy,
                "details": exc.details
            }
        }
    )

@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    detail = exc.detail
    if isinstance(detail, dict) and "code" in detail:
        content = {"error": detail}
    else:
        content = {
            "error": {
                "code": "HTTP_ERROR",
                "message": str(detail)
            }
        }
    return JSONResponse(status_code=exc.status_code, content=content)

@app.exception_handler(Exception)
async def general_exception_handler(request: Request, exc: Exception):
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "error": {
                "code": "INTERNAL_SERVER_ERROR",
                "message": "An unexpected server error occurred during face processing."
            }
        }
    )

# Include Routers
app.include_router(health.router)
app.include_router(detect.router)
app.include_router(enroll.router)
app.include_router(gallery.router)
app.include_router(identify.router)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app.main:app", host=settings.HOST, port=settings.PORT, reload=settings.DEBUG)
