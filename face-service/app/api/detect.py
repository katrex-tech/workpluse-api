import time
from fastapi import APIRouter, UploadFile, File, Depends, HTTPException, Security, status
from fastapi.security import SecurityScopes
from app.config import settings
from app.schemas import DetectionResponse, ErrorResponse
from app.security import get_current_auth, AuthenticatedContext
from app.pipeline.engine import FaceEngine
from app.pipeline.quality import QualityException

router = APIRouter(prefix="/v1/faces", tags=["Face Detection"])

@router.post(
    "/detect",
    response_model=DetectionResponse,
    responses={
        400: {"model": ErrorResponse},
        401: {"model": ErrorResponse},
        403: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    }
)
async def detect_faces(
    file: UploadFile = File(..., description="Image file (JPEG, PNG, WebP)"),
    auth: AuthenticatedContext = Security(get_current_auth, scopes=["faces:detect"])
):
    """
    Detects faces in an uploaded image.
    Returns bounding box coordinates, 5 facial landmarks, and full quality metrics
    (sharpness, lighting, pose angles, and bounded clipping evaluation).
    """
    start_time = time.perf_counter()
    image_bytes = await file.read()

    engine = FaceEngine.get_instance()
    try:
        bgr_img, faces = await engine.detect_faces(image_bytes, strict_for_enrollment=False)
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
            detail={"code": "DETECTION_ERROR", "message": f"Detection failed: {str(e)}"}
        )

    detected_faces_list = [f[0] for f in faces]
    elapsed_ms = round((time.perf_counter() - start_time) * 1000, 2)

    return DetectionResponse(
        faces_detected_count=len(detected_faces_list),
        faces=detected_faces_list,
        pipeline_version=settings.PIPELINE_VERSION,
        processing_time_ms=elapsed_ms
    )
