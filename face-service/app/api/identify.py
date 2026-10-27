import time
from typing import Optional, List
from fastapi import (
    APIRouter, UploadFile, File, Form, Depends,
    HTTPException, Security, status
)
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import settings
from app.database import get_db, search_gallery_exact
from app.models import FaceAuditLog
from app.schemas import (
    IdentificationResult, BatchIdentificationResponse,
    CandidateMatch, ErrorResponse
)
from app.security import (
    get_current_auth, AuthenticatedContext,
    create_thumbnail_token
)
from app.pipeline.engine import FaceEngine
from app.pipeline.quality import QualityException

router = APIRouter(prefix="/v1/faces", tags=["Face Identification & Verification"])

@router.post(
    "/identify",
    response_model=IdentificationResult,
    responses={
        400: {"model": ErrorResponse},
        401: {"model": ErrorResponse},
        403: {"model": ErrorResponse},
        422: {"model": ErrorResponse}
    }
)
async def identify_face(
    file: UploadFile = File(..., description="Camera capture frame to identify or verify"),
    target_user_id: Optional[str] = Form(None, description="Optional target user ID for 1:1 verification mode"),
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:identify"]),
    db: AsyncSession = Depends(get_db)
):
    """
    Identifies or verifies an uploaded face against the tenant's enrolled gallery.
    - Server-controlled matching policy: enforces MATCH_THRESHOLD (0.65) and AMBIGUITY_MARGIN (0.08).
    - If 0 faces detected, returns 'unknown' with null similarity (never manufactured 0 score).
    - Returns decision: 'matched', 'unknown', or 'ambiguous'.
    - Returns top candidate even when below threshold for transparency and diagnostics.
    - Returns runner-up candidate score for ambiguity auditing.
    - Returns scanned preview and protected enrolled thumbnail reference.
    - Returns liveness as 'not_evaluated' unless presentation attack detector is active.
    """
    start_time = time.perf_counter()
    image_bytes = await file.read()
    engine = FaceEngine.get_instance()

    try:
        probes = await engine.extract_probe_faces(image_bytes)
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
            detail={"code": "IDENTIFICATION_PROCESSING_ERROR", "message": str(e)}
        )

    elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)

    # 1. No face detected in probe capture
    if not probes:
        return IdentificationResult(
            decision="unknown",
            matched_user_id=None,
            closest_candidate=None,
            runner_up=None,
            threshold_applied=settings.MATCH_THRESHOLD,
            ambiguity_margin=settings.AMBIGUITY_MARGIN,
            detected_face=None,
            scanned_preview_base64=None,
            enrolled_thumbnail_url=None,
            liveness="not_evaluated",
            pipeline_version=settings.PIPELINE_VERSION,
            processing_time_ms=elapsed_ms
        )

    # For standard single-face capture, take primary detected face
    probe = probes[0]
    probe_emb = probe["embedding"]
    detected_face = probe["detected_face"]
    preview_b64 = probe["preview_base64"]

    # 2. Search gallery (scoped strictly to auth.tenant_id and optional target_user_id)
    candidates = await search_gallery_exact(
        session=db,
        tenant_id=auth.tenant_id,
        probe_embedding=probe_emb,
        target_user_id=target_user_id
    )

    # Populate thumbnail token URLs for candidates
    for cand in candidates:
        u_id = cand["user_id"]
        token = create_thumbnail_token(auth.tenant_id, u_id)
        cand["thumbnail_url"] = f"{settings.API_V1_PREFIX}/users/{u_id}/face/thumbnail?token={token}"

    # 3. Evaluate server matching policy
    decision, matched_uid, closest_cand, runner_up_cand = engine.evaluate_matching_decision(
        candidates=candidates,
        threshold=settings.MATCH_THRESHOLD,
        margin=settings.AMBIGUITY_MARGIN
    )

    # Protected enrolled thumbnail URL of matched / closest user
    enrolled_thumb_url = None
    if matched_uid:
        enrolled_thumb_url = closest_cand.thumbnail_url
    elif closest_cand:
        enrolled_thumb_url = closest_cand.thumbnail_url

    total_elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)

    # 4. Audit Log
    audit = FaceAuditLog(
        tenant_id=auth.tenant_id,
        user_id=target_user_id or matched_uid,
        action="identify",
        decision=decision,
        matched_user_id=matched_uid,
        score=closest_cand.score if closest_cand else None,
        runner_up_score=runner_up_cand.score if runner_up_cand else None,
        faces_detected_count=len(probes),
        processing_time_ms=total_elapsed_ms
    )
    db.add(audit)
    await db.commit()

    return IdentificationResult(
        decision=decision,
        matched_user_id=matched_uid,
        closest_candidate=closest_cand,
        runner_up=runner_up_cand,
        threshold_applied=settings.MATCH_THRESHOLD,
        ambiguity_margin=settings.AMBIGUITY_MARGIN,
        detected_face=detected_face,
        scanned_preview_base64=preview_b64,
        enrolled_thumbnail_url=enrolled_thumb_url,
        liveness="not_evaluated",
        pipeline_version=settings.PIPELINE_VERSION,
        processing_time_ms=total_elapsed_ms
    )

@router.post(
    "/identify-batch",
    response_model=BatchIdentificationResponse,
    responses={401: {"model": ErrorResponse}, 403: {"model": ErrorResponse}}
)
async def identify_batch(
    files: List[UploadFile] = File(..., description="Batch of image files (up to 16)"),
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:identify"]),
    db: AsyncSession = Depends(get_db)
):
    """Identifies faces across a bounded batch of uploaded images."""
    if len(files) > settings.MAX_BATCH_SIZE:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Batch size ({len(files)}) exceeds maximum limit of {settings.MAX_BATCH_SIZE} images."
        )

    start_time = time.perf_counter()
    results = []

    for file in files:
        res = await identify_face(file=file, target_user_id=None, auth=auth, db=db)
        results.append(res)

    total_elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)
    return BatchIdentificationResponse(
        results=results,
        total_probes=len(results),
        processing_time_ms=total_elapsed_ms
    )
