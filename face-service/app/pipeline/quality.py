import io
import math
from typing import Tuple, Optional, Dict, Any
import numpy as np
import cv2
from PIL import Image, ImageOps
from app.config import settings
from app.schemas import Point2D, Landmarks5, BoundingBox, PoseAngles, QualityMetrics

class QualityException(Exception):
    def __init__(self, code: str, message: str, actionable_remedy: str, details: Optional[Dict[str, Any]] = None):
        super().__init__(message)
        self.code = code
        self.message = message
        self.actionable_remedy = actionable_remedy
        self.details = details or {}

def decode_and_correct_image(image_bytes: bytes) -> np.ndarray:
    """
    Validates byte size, verifies image integrity, corrects EXIF orientation,
    and returns a BGR NumPy array for OpenCV processing.
    """
    if len(image_bytes) > settings.MAX_IMAGE_SIZE_BYTES:
        raise QualityException(
            code="IMAGE_TOO_LARGE",
            message=f"Image size ({len(image_bytes)} bytes) exceeds maximum limit ({settings.MAX_IMAGE_SIZE_BYTES} bytes).",
            actionable_remedy="Compress or resize the image before uploading."
        )

    try:
        pil_img = Image.open(io.BytesIO(image_bytes))
        pil_img.verify()
        # Re-open after verify() as verify() invalidates file pointer
        pil_img = Image.open(io.BytesIO(image_bytes))
    except Exception as e:
        raise QualityException(
            code="INVALID_IMAGE_FORMAT",
            message="Uploaded file is not a valid or readable image (JPEG, PNG, or WebP expected).",
            actionable_remedy="Ensure the file is an intact image with valid headers.",
            details={"error": str(e)}
        )

    # Correct EXIF orientation
    try:
        pil_img = ImageOps.exif_transpose(pil_img)
    except Exception:
        pass

    # Convert to RGB
    if pil_img.mode != "RGB":
        pil_img = pil_img.convert("RGB")

    width, height = pil_img.size
    if width > settings.MAX_IMAGE_DIMENSION or height > settings.MAX_IMAGE_DIMENSION:
        # Resize maintaining aspect ratio
        pil_img.thumbnail((settings.MAX_IMAGE_DIMENSION, settings.MAX_IMAGE_DIMENSION), Image.Resampling.LANCZOS)
        width, height = pil_img.size

    # Convert RGB PIL Image to BGR NumPy array for OpenCV
    rgb_arr = np.array(pil_img)
    bgr_arr = cv2.cvtColor(rgb_arr, cv2.COLOR_RGB2BGR)
    return bgr_arr

def check_blur(bgr_img: np.ndarray, face_crop: Optional[np.ndarray] = None) -> Tuple[float, bool]:
    """
    Calculates Laplacian variance. Sharp images yield high variance, blurry images yield low variance.
    """
    gray = cv2.cvtColor(bgr_img, cv2.COLOR_BGR2GRAY)
    laplacian = cv2.Laplacian(gray, cv2.CV_64F)
    variance = float(laplacian.var())
    is_blurry = variance < settings.BLUR_THRESHOLD
    return variance, is_blurry

def check_lighting(bgr_img: np.ndarray, face_crop: Optional[np.ndarray] = None) -> Tuple[float, bool]:
    """
    Calculates mean luminance. Checks for underexposure (too dark) or overexposure (too bright/glare).
    """
    target = face_crop if face_crop is not None and face_crop.size > 0 else bgr_img
    gray = cv2.cvtColor(target, cv2.COLOR_BGR2GRAY)
    mean_val = float(np.mean(gray))
    is_valid = (settings.MIN_BRIGHTNESS <= mean_val <= settings.MAX_BRIGHTNESS)
    return mean_val, is_valid

def estimate_pose(landmarks: Landmarks5) -> PoseAngles:
    """
    Estimates 3D head pose angles (yaw, pitch, roll) from 5 standard facial landmarks.
    """
    # 1. Roll: angle between eyes in image plane
    dx = landmarks.left_eye.x - landmarks.right_eye.x
    dy = landmarks.left_eye.y - landmarks.right_eye.y
    roll = math.degrees(math.atan2(dy, dx if abs(dx) > 1e-5 else 1e-5))

    # 2. Yaw: horizontal asymmetry between nose tip and eyes
    eye_mid_x = (landmarks.right_eye.x + landmarks.left_eye.x) / 2.0
    eye_dist = math.hypot(dx, dy)
    if eye_dist > 1e-5:
        # Distance of nose from eye midpoint normalized by eye distance
        yaw_ratio = (landmarks.nose_tip.x - eye_mid_x) / (eye_dist / 2.0)
        # Approximate yaw in degrees [-90 to +90]
        yaw = float(np.clip(yaw_ratio * 45.0, -85.0, 85.0))
    else:
        yaw = 0.0

    # 3. Pitch: vertical position of nose tip relative to eyes and mouth
    eye_mid_y = (landmarks.right_eye.y + landmarks.left_eye.y) / 2.0
    mouth_mid_y = (landmarks.right_mouth.y + landmarks.left_mouth.y) / 2.0
    vertical_span = mouth_mid_y - eye_mid_y
    if vertical_span > 1e-5:
        expected_nose_y = eye_mid_y + 0.45 * vertical_span
        pitch_ratio = (landmarks.nose_tip.y - expected_nose_y) / (vertical_span / 2.0)
        pitch = float(np.clip(pitch_ratio * 40.0, -75.0, 75.0))
    else:
        pitch = 0.0

    return PoseAngles(yaw=round(yaw, 1), pitch=round(pitch, 1), roll=round(roll, 1))

def validate_face_geometry_and_quality(
    bgr_img: np.ndarray,
    box: BoundingBox,
    landmarks: Landmarks5,
    strict_for_enrollment: bool = False
) -> QualityMetrics:
    """
    Comprehensive quality check:
    - Bounded overflow tolerance when landmarks are inside the frame
    - Actionable errors for small faces, clipped faces, blur, lighting, and pose
    """
    img_h, img_w = bgr_img.shape[:2]

    # 1. Face Size Check
    if box.width < settings.MIN_FACE_SIZE or box.height < settings.MIN_FACE_SIZE:
        if strict_for_enrollment:
            raise QualityException(
                code="FACE_TOO_SMALL",
                message=f"Detected face is too small ({int(box.width)}x{int(box.height)}px). Minimum required is {settings.MIN_FACE_SIZE}x{settings.MIN_FACE_SIZE}px.",
                actionable_remedy="Move closer to the camera to fill more of the frame."
            )
        is_size_valid = False
    else:
        is_size_valid = True

    # 2. Clipping Check with Bounded Detector-Box Overflow
    # Bounded overflow: detector box can extend up to MAX_CLIPPING_RATIO beyond boundary
    # IF all 5 landmarks remain strictly within image boundaries
    max_overflow_x = box.width * settings.MAX_CLIPPING_RATIO
    max_overflow_y = box.height * settings.MAX_CLIPPING_RATIO

    x_min, y_min = box.x, box.y
    x_max, y_max = box.x + box.width, box.y + box.height

    lm_pts = [
        landmarks.right_eye, landmarks.left_eye,
        landmarks.nose_tip,
        landmarks.right_mouth, landmarks.left_mouth
    ]
    all_landmarks_inside = all(0 <= pt.x < img_w and 0 <= pt.y < img_h for pt in lm_pts)

    is_severely_clipped = (
        x_min < -max_overflow_x or
        y_min < -max_overflow_y or
        x_max > img_w + max_overflow_x or
        y_max > img_h + max_overflow_y or
        not all_landmarks_inside
    )

    if is_severely_clipped:
        if strict_for_enrollment:
            raise QualityException(
                code="FACE_CLIPPED",
                message="Face is clipped at image edges. Facial landmarks must be fully visible.",
                actionable_remedy="Center your face inside the camera frame and step slightly back."
            )
        is_clipping_valid = False
    else:
        is_clipping_valid = True

    # Crop face region for localized blur & lighting checks
    c_x1 = max(0, int(box.x))
    c_y1 = max(0, int(box.y))
    c_x2 = min(img_w, int(box.x + box.width))
    c_y2 = min(img_h, int(box.y + box.height))
    face_crop = bgr_img[c_y1:c_y2, c_x1:c_x2] if (c_x2 > c_x1 and c_y2 > c_y1) else None

    # 3. Blur Check
    blur_score, is_blurry = check_blur(bgr_img, face_crop)
    if is_blurry and strict_for_enrollment:
        raise QualityException(
            code="IMAGE_BLURRY",
            message=f"Image sharpness score ({round(blur_score, 1)}) is below threshold ({settings.BLUR_THRESHOLD}).",
            actionable_remedy="Hold the camera steady, clean the camera lens, and ensure good focus."
        )

    # 4. Lighting Check
    brightness, is_lighting_valid = check_lighting(bgr_img, face_crop)
    if not is_lighting_valid and strict_for_enrollment:
        remedy = "Move to a well-lit area without strong backlighting or harsh glare."
        msg = "Image is underexposed (too dark)." if brightness < settings.MIN_BRIGHTNESS else "Image is overexposed (too bright/glare)."
        raise QualityException(
            code="POOR_LIGHTING",
            message=msg,
            actionable_remedy=remedy,
            details={"brightness": round(brightness, 1)}
        )

    # 5. Pose Check
    pose_angles = estimate_pose(landmarks)
    is_pose_valid = (abs(pose_angles.yaw) <= 28.0 and abs(pose_angles.pitch) <= 25.0 and abs(pose_angles.roll) <= 25.0)
    if not is_pose_valid and strict_for_enrollment:
        raise QualityException(
            code="INVALID_POSE",
            message=f"Head pose is tilted or turned (yaw: {pose_angles.yaw}°, pitch: {pose_angles.pitch}°, roll: {pose_angles.roll}°).",
            actionable_remedy="Look straight at the camera with your face upright."
        )

    return QualityMetrics(
        blur_score=round(blur_score, 1),
        is_blurry=is_blurry,
        brightness=round(brightness, 1),
        is_lighting_valid=is_lighting_valid,
        is_face_size_valid=is_size_valid,
        is_clipping_valid=is_clipping_valid,
        is_pose_valid=is_pose_valid,
        pose_angles=pose_angles
    )
