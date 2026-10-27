import datetime
from typing import List, Optional, Dict, Any, Tuple
from pydantic import BaseModel, Field

# ----------------- Quality & Geometry -----------------

class Point2D(BaseModel):
    x: float
    y: float

class Landmarks5(BaseModel):
    right_eye: Point2D
    left_eye: Point2D
    nose_tip: Point2D
    right_mouth: Point2D
    left_mouth: Point2D

class BoundingBox(BaseModel):
    x: float
    y: float
    width: float
    height: float

class PoseAngles(BaseModel):
    yaw: float = Field(..., description="Estimated horizontal turn in degrees")
    pitch: float = Field(..., description="Estimated vertical tilt in degrees")
    roll: float = Field(..., description="Estimated in-plane rotation in degrees")

class QualityMetrics(BaseModel):
    blur_score: float = Field(..., description="Laplacian variance sharpness metric")
    is_blurry: bool
    brightness: float = Field(..., description="Mean pixel intensity [0-255]")
    is_lighting_valid: bool
    is_face_size_valid: bool
    is_clipping_valid: bool
    is_pose_valid: bool
    pose_angles: PoseAngles

class DetectedFace(BaseModel):
    box: BoundingBox
    confidence: float
    landmarks: Landmarks5
    quality: QualityMetrics

# ----------------- Health -----------------

class HealthStatus(BaseModel):
    status: str = "ok"
    timestamp: datetime.datetime = Field(default_factory=datetime.datetime.utcnow)
    version: str
    model_loaded: bool
    database_connected: bool

# ----------------- Detection Response -----------------

class DetectionResponse(BaseModel):
    faces_detected_count: int
    faces: List[DetectedFace]
    pipeline_version: str
    processing_time_ms: float

# ----------------- Candidates & Matching -----------------

class CandidateMatch(BaseModel):
    user_id: str
    score: float = Field(..., description="Cosine similarity score [-1.0 to 1.0]")
    enrolled_at: Optional[str] = None
    thumbnail_url: Optional[str] = None

class IdentificationResult(BaseModel):
    decision: str = Field(..., description="'matched', 'unknown', or 'ambiguous'")
    matched_user_id: Optional[str] = Field(None, description="Only populated when decision is 'matched'")
    closest_candidate: Optional[CandidateMatch] = Field(None, description="Top candidate even when below threshold")
    runner_up: Optional[CandidateMatch] = Field(None, description="Second highest candidate for ambiguity audit")
    threshold_applied: float
    ambiguity_margin: float
    detected_face: Optional[DetectedFace] = None
    scanned_preview_base64: Optional[str] = None
    enrolled_thumbnail_url: Optional[str] = None
    liveness: str = "not_evaluated"
    pipeline_version: str
    processing_time_ms: float

class BatchIdentificationResponse(BaseModel):
    results: List[IdentificationResult]
    total_probes: int
    processing_time_ms: float

# ----------------- Enrollment & Gallery -----------------

class EnrollmentResponse(BaseModel):
    status: str = "enrolled"
    tenant_id: str
    user_id: str
    enrolled_at: datetime.datetime
    face_details: Optional[DetectedFace] = None
    thumbnail_url: str
    model_checksum: str
    pipeline_version: str
    idempotency_key: Optional[str] = None
    processing_time_ms: float

class EnrollmentItem(BaseModel):
    user_id: str
    tenant_id: str
    enrolled_at: datetime.datetime
    thumbnail_url: str
    model_name: str
    pipeline_version: str
    is_active: bool

class PaginatedEnrollmentsResponse(BaseModel):
    page: int
    page_size: int
    total: int
    total_pages: int
    items: List[EnrollmentItem]

class EnrollmentCountResponse(BaseModel):
    tenant_id: str
    total_active_enrollments: int

class DeleteEnrollmentResponse(BaseModel):
    status: str = "deleted"
    tenant_id: str
    user_id: str
    deleted_at: datetime.datetime = Field(default_factory=datetime.datetime.utcnow)
    message: str = "Face biometric enrollment and reference thumbnail permanently removed."

# ----------------- Error Schemas -----------------

class ErrorDetail(BaseModel):
    code: str
    message: str
    actionable_remedy: Optional[str] = None
    details: Optional[Dict[str, Any]] = None

class ErrorResponse(BaseModel):
    error: ErrorDetail
