import os
from typing import List, Optional
from pydantic import Field
from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    SERVICE_NAME: str = "WorkPulse Face Recognition Service"
    ENVIRONMENT: str = "development"
    DEBUG: bool = False
    HOST: str = "0.0.0.0"
    PORT: int = 8000
    API_V1_PREFIX: str = "/v1"

    # Database: Supports SQLite (aiosqlite) and PostgreSQL with pgvector (asyncpg)
    DATABASE_URL: str = Field(
        default="sqlite+aiosqlite:///./data/faces.db",
        description="Async database connection string"
    )
    SYNC_DATABASE_URL: Optional[str] = Field(
        default="sqlite:///./data/faces.db",
        description="Sync connection string for Alembic migrations"
    )

    # Security & Tokens
    SECRET_KEY: str = Field(
        default="c908f9219b25dc4c85918ff88d313c907bc9d9e6e104a62173ea6e987c2f6d0f",
        description="JWT secret key for verifying access tokens"
    )
    JWT_ALGORITHM: str = "HS256"
    JWT_ISSUER: str = "workpulse"
    JWT_AUDIENCE: str = "workpulse-face-service"
    API_KEYS: str = Field(
        default="wp_master_secret_key_2026,admin-secret-key",
        description="Comma-separated list of valid static server-to-server API keys"
    )
    DEFAULT_TENANT_ID: str = "tenant_workpulse"

    # Server-Controlled Matching Policy
    MATCH_THRESHOLD: float = 0.65
    AMBIGUITY_MARGIN: float = 0.08
    MIN_DET_CONFIDENCE: float = 0.60

    # Quality & Preprocessing Thresholds
    MAX_IMAGE_SIZE_BYTES: int = 10 * 1024 * 1024  # 10 MB
    MAX_IMAGE_DIMENSION: int = 1280
    MIN_FACE_SIZE: int = 45
    MAX_CLIPPING_RATIO: float = 0.15
    BLUR_THRESHOLD: float = 18.0
    MIN_BRIGHTNESS: float = 35.0
    MAX_BRIGHTNESS: float = 230.0

    # Pipeline & Weights Info
    PIPELINE_VERSION: str = "1.0.0"
    MODEL_NAME: str = "w600k_mbf"
    MODEL_EMBEDDING_DIM: int = 512
    NORMALIZATION: str = "L2"
    ALIGNMENT: str = "arcface_112x112"
    DETECTOR_MODEL: str = "face_detection_yunet_2023mar"
    WEIGHTS_DIR: str = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "models", "weights")
    STORAGE_DIR: str = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "thumbnails")

    # Concurrency
    INFERENCE_CONCURRENCY: int = 4
    MAX_BATCH_SIZE: int = 16

    @property
    def api_keys_list(self) -> List[str]:
        return [k.strip() for k in self.API_KEYS.split(",") if k.strip()]

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        extra = "ignore"

settings = Settings()
