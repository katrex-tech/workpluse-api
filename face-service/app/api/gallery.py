import os
import math
import time
from typing import Optional
from fastapi import (
    APIRouter, Query, Depends, HTTPException, Security,
    status, Response
)
from fastapi.responses import FileResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, and_, delete

from app.config import settings
from app.database import get_db
from app.models import FaceEnrollment, FaceAuditLog
from app.schemas import (
    PaginatedEnrollmentsResponse, EnrollmentItem, EnrollmentCountResponse,
    DeleteEnrollmentResponse, ErrorResponse
)
from app.security import (
    get_current_auth, AuthenticatedContext,
    create_thumbnail_token, verify_thumbnail_token
)

router = APIRouter(tags=["Enrollment Gallery"])

@router.get(
    "/v1/enrollments",
    response_model=PaginatedEnrollmentsResponse,
    responses={401: {"model": ErrorResponse}, 403: {"model": ErrorResponse}}
)
async def list_enrollments(
    page: int = Query(1, ge=1, description="Page number starting at 1"),
    page_size: int = Query(20, ge=1, le=100, description="Items per page"),
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:manage"]),
    db: AsyncSession = Depends(get_db)
):
    """Lists enrolled users and protected thumbnail references for the tenant, paginated."""
    # Count total
    count_stmt = select(func.count(FaceEnrollment.id)).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.is_active == True
        )
    )
    total_res = await db.execute(count_stmt)
    total = total_res.scalar_one() or 0

    offset = (page - 1) * page_size
    items_stmt = select(FaceEnrollment).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.is_active == True
        )
    ).order_by(FaceEnrollment.created_at.desc()).offset(offset).limit(page_size)

    items_res = await db.execute(items_stmt)
    enrollments = items_res.scalars().all()

    items = []
    for enr in enrollments:
        token = create_thumbnail_token(auth.tenant_id, enr.user_id)
        thumb_url = f"{settings.API_V1_PREFIX}/users/{enr.user_id}/face/thumbnail?token={token}"
        items.append(
            EnrollmentItem(
                user_id=enr.user_id,
                tenant_id=enr.tenant_id,
                enrolled_at=enr.created_at,
                thumbnail_url=thumb_url,
                model_name=enr.model_name,
                pipeline_version=enr.pipeline_version,
                is_active=enr.is_active
            )
        )

    total_pages = max(1, math.ceil(total / page_size)) if total > 0 else 1

    return PaginatedEnrollmentsResponse(
        page=page,
        page_size=page_size,
        total=total,
        total_pages=total_pages,
        items=items
    )

@router.get(
    "/v1/enrollments/count",
    response_model=EnrollmentCountResponse,
    responses={401: {"model": ErrorResponse}, 403: {"model": ErrorResponse}}
)
async def get_enrollments_count(
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:manage"]),
    db: AsyncSession = Depends(get_db)
):
    """Returns the total number of active enrolled faces for the tenant."""
    count_stmt = select(func.count(FaceEnrollment.id)).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.is_active == True
        )
    )
    total_res = await db.execute(count_stmt)
    total = total_res.scalar_one() or 0
    return EnrollmentCountResponse(
        tenant_id=auth.tenant_id,
        total_active_enrollments=total
    )

@router.get(
    "/v1/users/{user_id}/face",
    response_model=EnrollmentItem,
    responses={
        401: {"model": ErrorResponse},
        403: {"model": ErrorResponse},
        404: {"model": ErrorResponse}
    }
)
async def get_user_face(
    user_id: str,
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:manage"]),
    db: AsyncSession = Depends(get_db)
):
    """Retrieves an enrolled user's metadata and protected thumbnail reference."""
    stmt = select(FaceEnrollment).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.user_id == user_id,
            FaceEnrollment.is_active == True
        )
    )
    res = await db.execute(stmt)
    enr = res.scalar_one_or_none()
    if not enr:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"No active face enrollment found for user '{user_id}'."
        )

    token = create_thumbnail_token(auth.tenant_id, user_id)
    thumb_url = f"{settings.API_V1_PREFIX}/users/{user_id}/face/thumbnail?token={token}"

    return EnrollmentItem(
        user_id=enr.user_id,
        tenant_id=enr.tenant_id,
        enrolled_at=enr.created_at,
        thumbnail_url=thumb_url,
        model_name=enr.model_name,
        pipeline_version=enr.pipeline_version,
        is_active=enr.is_active
    )

@router.get("/v1/users/{user_id}/face/thumbnail")
async def get_user_thumbnail(
    user_id: str,
    token: Optional[str] = Query(None, description="HMAC signed access token"),
    tenant_id: Optional[str] = Query(None, description="Tenant ID (if authenticated via token)"),
    db: AsyncSession = Depends(get_db)
):
    """
    Streams the protected face thumbnail image bytes.
    Access is granted only via short-lived HMAC signed token.
    Public direct access without valid authorization is rejected.
    """
    target_tenant = tenant_id or settings.DEFAULT_TENANT_ID

    if not token or not verify_thumbnail_token(target_tenant, user_id, token):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or expired thumbnail access token."
        )

    filename = f"{target_tenant}_{user_id}.jpg"
    filepath = os.path.join(settings.STORAGE_DIR, filename)
    if not os.path.exists(filepath):
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Thumbnail file not found on disk."
        )

    return FileResponse(filepath, media_type="image/jpeg")

@router.delete(
    "/v1/users/{user_id}/face",
    response_model=DeleteEnrollmentResponse,
    responses={
        401: {"model": ErrorResponse},
        403: {"model": ErrorResponse},
        404: {"model": ErrorResponse}
    }
)
async def delete_user_face(
    user_id: str,
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:manage"]),
    db: AsyncSession = Depends(get_db)
):
    """
    Deletes an enrolled face and its reference thumbnail.
    Immediately excludes the face from biometric matching.
    Cleans up thumbnail storage with retries.
    """
    stmt = select(FaceEnrollment).where(
        and_(
            FaceEnrollment.tenant_id == auth.tenant_id,
            FaceEnrollment.user_id == user_id,
            FaceEnrollment.is_active == True
        )
    )
    res = await db.execute(stmt)
    enr = res.scalar_one_or_none()
    if not enr:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"No active face enrollment found for user '{user_id}'."
        )

    # Hard delete from matching table
    await db.delete(enr)

    # Audit log
    audit = FaceAuditLog(
        tenant_id=auth.tenant_id,
        user_id=user_id,
        action="delete",
        decision="deleted",
        processing_time_ms=0.0
    )
    db.add(audit)
    await db.commit()

    # Clean up thumbnail file on disk with retry
    filename = f"{auth.tenant_id}_{user_id}.jpg"
    thumb_path = os.path.join(settings.STORAGE_DIR, filename)
    if os.path.exists(thumb_path):
        for _ in range(3):
            try:
                os.remove(thumb_path)
                break
            except Exception:
                time.sleep(0.05)

    return DeleteEnrollmentResponse(
        status="deleted",
        tenant_id=auth.tenant_id,
        user_id=user_id
    )
