import datetime
import json
from typing import List, Optional
from sqlalchemy import (
    Column, Integer, String, Boolean, DateTime, Float, Text,
    UniqueConstraint, Index, func, TypeDecorator
)
from sqlalchemy.orm import declarative_base

Base = declarative_base()

class EmbeddingVectorType(TypeDecorator):
    """
    Stores 512-D float vectors transparently across both SQLite (JSON text)
    and PostgreSQL (pgvector.sqlalchemy.Vector when enabled).
    """
    impl = Text
    cache_ok = True

    def process_bind_param(self, value, dialect):
        if value is None:
            return None
        if isinstance(value, (list, tuple)):
            return json.dumps([float(x) for x in value])
        return str(value)

    def process_result_value(self, value, dialect) -> Optional[List[float]]:
        if value is None:
            return None
        if isinstance(value, list):
            return [float(x) for x in value]
        try:
            parsed = json.loads(value)
            return [float(x) for x in parsed]
        except Exception:
            return None

class FaceEnrollment(Base):
    __tablename__ = "face_enrollments"

    id = Column(Integer, primary_key=True, autoincrement=True)
    tenant_id = Column(String(64), nullable=False, index=True)
    user_id = Column(String(64), nullable=False, index=True)
    
    # Store L2-normalized 512-D embedding
    embedding = Column(EmbeddingVectorType, nullable=False)
    
    # Secure thumbnail file reference
    thumbnail_path = Column(String(255), nullable=True)
    thumbnail_mime = Column(String(32), default="image/jpeg")
    
    # Metadata for parity and compatibility tracking
    model_name = Column(String(64), nullable=False)
    model_checksum = Column(String(64), nullable=False)
    embedding_dim = Column(Integer, default=512, nullable=False)
    normalization = Column(String(32), default="L2", nullable=False)
    alignment_config = Column(String(64), default="arcface_112x112", nullable=False)
    pipeline_version = Column(String(32), default="1.0.0", nullable=False)
    
    # Consent and idempotency
    consent_given = Column(Boolean, nullable=False, default=False)
    idempotency_key = Column(String(128), nullable=True, index=True)
    is_active = Column(Boolean, nullable=False, default=True, index=True)
    
    # Timestamps
    created_at = Column(DateTime, default=datetime.datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.datetime.utcnow, onupdate=datetime.datetime.utcnow, nullable=False)

    __table_args__ = (
        # Exactly one active enrollment per tenant/user_id
        UniqueConstraint("tenant_id", "user_id", name="uq_tenant_user_face"),
        Index("idx_tenant_user_active", "tenant_id", "user_id", "is_active"),
    )

class FaceAuditLog(Base):
    __tablename__ = "face_audit_logs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    tenant_id = Column(String(64), nullable=False, index=True)
    user_id = Column(String(64), nullable=True, index=True)
    action = Column(String(32), nullable=False)  # detect, enroll, identify, delete
    decision = Column(String(32), nullable=True)  # matched, unknown, ambiguous, error
    matched_user_id = Column(String(64), nullable=True)
    score = Column(Float, nullable=True)
    runner_up_score = Column(Float, nullable=True)
    faces_detected_count = Column(Integer, default=1)
    processing_time_ms = Column(Float, nullable=False)
    ip_address = Column(String(45), nullable=True)
    created_at = Column(DateTime, default=datetime.datetime.utcnow, nullable=False)

    __table_args__ = (
        Index("idx_audit_tenant_action", "tenant_id", "action", "created_at"),
    )
