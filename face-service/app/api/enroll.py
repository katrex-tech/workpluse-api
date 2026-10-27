import os
import time
import datetime
from typing import Optional
from fastapi import (
    APIRouter, UploadFile, File, Form, Header, Depends,
    HTTPException, Security, status, Request
)
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, and_

from app.config import settings
from app.database import get_db
from app.models import FaceEnrollment, FaceAuditLog
from app.schemas import EnrollmentResponse, ErrorResponse
from app.security import get_current_auth, AuthenticatedContext, create_thumbnail_token
from app.pipeline.engine import FaceEngine
from app.pipeline.quality import QualityException

router = APIRouter(prefix="/v1/users", tags=["Face Enrollment"])

@router.post(
    "/{user_id}/face",
    response_model=EnrollmentResponse,
    status_code=status.HTTP_201_CREATED,
    responses={
        400: {"model": ErrorResponse},
        401: {"model": ErrorResponse},
        403: {"model": ErrorResponse},
        409: {"model": ErrorResponse, "description": "User already has an active face enrollment"},
        422: {"model": ErrorResponse, "description": "Validation or image quality failure"}
    }
)
async def enroll_face(
    user_id: str,
    file: UploadFile = File(..., description="Frontal face photograph for biometric enrollment"),
    consent: bool = Form(..., description="Explicit user consent to store biometric embedding and face thumbnail"),
    idempotency_key: Optional[str] = Header(None, alias="Idempotency-Key"),
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:enroll"]),
    db: AsyncSession = Depends(get_db)
):
    """
    Enrolls a user's facial biometrics.
    - Requires explicit consent.
    - Enforces exactly one face per image.
    - Enforces single active enrollment per tenant/user_id (HTTP 409 Conflict if already enrolled).
    - Supports Idempotency-Key header for safe retries.
    - Stores strictly the aligned 112x112 thumbnail; full original upload is not retained.
    """
    start_time = time.perf_counter()

    # 1. Require explicit consent
    if not consent:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "code": "CONSENT_REQUIRED",
                "message": "Explicit user consent is mandatory for storing biometric embeddings and face thumbnails.",
                "actionable_remedy": "Provide consent=true in the enrollment form."
            }
        )

    # 2. Check Idempotency Key
    if idempotency_key:
        idemp_stmt = select(FaceEnrollment).where(
            and_(
                FaceEnrollment.tenant_id == auth.tenant_id,
                FaceEnrollment.user_id == user_id,
                FaceEnrollment.idempotency_key == idempotency_key,
                FaceEnrollment.is_active == True
            )
        )
        idemp_res = await db.execute(idemp_stmt)
        existing_idemp = idemp_res.scalar_one_or_none()
        if existing_idemp:
            # Idempotent replay: return existing enrollment details
            thumb_token = create_thumbnail_token(auth.tenant_id, user_id)
            thumb_url = f"{settings.API_V1_PREFIX}/users/{user_id}/face/thumbnail?token={thumb_token}"
            engine = FaceEngine.get_instance()
            # Return cached response with HTTP 200/201
            return EnrollmentResponse(
                status="enrolled",
                tenant_id=auth.tenant_id,
                user_id=user_id,
                enrolled_at=existing_idemp.created_at,
                face_details=None,  # Not recomputed for idempotent replay
                thumbnail_url=thumb_url,
                model_checksum=existing_idemp.model_checksum,
                pipeline_version=existing_idemp.pipeline_version,
                idempotency_key=idempotency_key,
                processing_time_ms=0.0
            )

    # 3. Check Uniqueness (HTTP 409 Conflict)
    existing_stmt = select(FaceEnrollment).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.user_id == user_id,
            FaceEnrollment.is_active == True
        )
    )
    existing_res = await db.execute(existing_stmt)
    existing_enrollment = existing_res.scalar_one_or_none()

    if existing_enrollment:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "code": "ALREADY_ENROLLED",
                "message": f"User '{user_id}' already has an active face enrollment.",
                "actionable_remedy": "Delete the existing enrollment first via DELETE /v1/users/{user_id}/face before enrolling a new face."
            }
        )

    # 4. Process Biometric Pipeline
    image_bytes = await file.read()
    engine = FaceEngine.get_instance()

    try:
        thumbnail_bytes, embedding_512, detected_face = await engine.process_enrollment(image_bytes)
    except QualityException as qe:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "code": qe.code,
                "message": qe.message,
                "actionable_remedy": qe.actionable_remedy,
                "details": qe.details
            }
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={"code": "ENROLLMENT_PROCESSING_ERROR", "message": str(e)}
        )

    # 5. Persist Aligned Thumbnail (Original upload is discarded)
    filename = f"{auth.tenant_id}_{user_id}.jpg"
    thumb_path = os.path.join(settings.STORAGE_DIR, filename)
    with open(thumb_path, "wb") as f:
        f.write(thumbnail_bytes)

    # 6. Database Transaction
    enrollment = FaceEnrollment(
        tenant_id=auth.tenant_id,
        user_id=user_id,
        embedding=embedding_512.tolist(),
        thumbnail_path=thumb_path,
        thumbnail_mime="image/jpeg",
        model_name=settings.MODEL_NAME,
        model_checksum=engine.recognizer.checksum,
        embedding_dim=settings.MODEL_EMBEDDING_DIM,
        normalization=settings.NORMALIZATION,
        alignment_config=settings.ALIGNMENT,
        pipeline_version=settings.PIPELINE_VERSION,
        consent_given=True,
        idempotency_key=idempotency_key,
        is_active=True
    )
    db.add(enrollment)

    # Log action
    audit = FaceAuditLog(
        tenant_id=auth.tenant_id,
        user_id=user_id,
        action="enroll",
        decision="enrolled",
        score=1.0,
        processing_time_ms=round((time.perf_counter() - start_time) * 1000, 2)
    )
    db.add(audit)

    await db.commit()
    await db.refresh(enrollment)

    elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)
    thumb_token = create_thumbnail_token(auth.tenant_id, user_id)
    thumb_url = f"{settings.API_V1_PREFIX}/users/{user_id}/face/thumbnail?token={thumb_token}"

    return EnrollmentResponse(
        status="enrolled",
        tenant_id=auth.tenant_id,
        user_id=user_id,
        enrolled_at=enrollment.created_at,
        face_details=detected_face,
        thumbnail_url=thumb_url,
        model_checksum=enrollment.model_checksum,
        pipeline_version=enrollment.pipeline_version,
        idempotency_key=idempotency_key,
        processing_time_ms=elapsed_ms
    )
