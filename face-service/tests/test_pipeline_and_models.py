import os
import hashlib
import numpy as np
import cv2
import pytest
from app.config import settings
from app.pipeline.recognizer import ArcFaceRecognizer
from app.pipeline.aligner import FaceAligner, ARCFACE_REFERENCE_112
from app.pipeline.quality import (
    check_blur, check_lighting, estimate_pose,
    validate_face_geometry_and_quality, QualityException
)
from app.schemas import BoundingBox, Landmarks5, Point2D

def test_real_model_weights_and_checksum():
    """Smoke test: verifies presence, provenance, and pinned SHA-256 hash of ArcFace weights."""
    model_path = os.path.join(settings.WEIGHTS_DIR, "w600k_mbf.onnx")
    assert os.path.exists(model_path), f"ArcFace weights missing at {model_path}"
    
    sha256 = hashlib.sha256()
    with open(model_path, "rb") as f:
        while chunk := f.read(65536):
            sha256.update(chunk)
    computed_hash = sha256.hexdigest()

    assert computed_hash == ArcFaceRecognizer.EXPECTED_SHA256, (
        f"Model weights hash mismatch: expected {ArcFaceRecognizer.EXPECTED_SHA256}, got {computed_hash}"
    )

def test_arcface_embedding_generation_and_l2_normalization():
    """Verifies that recognition outputs exactly 512 dimensions and unit L2 length."""
    recognizer = ArcFaceRecognizer()
    assert recognizer.checksum == ArcFaceRecognizer.EXPECTED_SHA256

    dummy_face = np.full((112, 112, 3), 120, dtype=np.uint8)
    emb = recognizer.compute_embedding(dummy_face)

    assert isinstance(emb, np.ndarray)
    assert emb.shape == (512,), f"Expected 512-D vector, got {emb.shape}"
    assert emb.dtype == np.float32

    # L2 norm must equal 1.0
    l2_norm = float(np.linalg.norm(emb))
    assert pytest.approx(l2_norm, abs=1e-4) == 1.0

def test_cosine_similarity_identity_and_orthogonality():
    """Tests mathematical properties of cosine similarity score."""
    recognizer = ArcFaceRecognizer()
    
    v1 = np.random.randn(512).astype(np.float32)
    v1 = v1 / np.linalg.norm(v1)

    # Identical vector score must be 1.0
    sim_self = recognizer.compute_similarity(v1, v1)
    assert pytest.approx(sim_self, abs=1e-5) == 1.0

    # Opposite vector score must be -1.0
    sim_neg = recognizer.compute_similarity(v1, -v1)
    assert pytest.approx(sim_neg, abs=1e-5) == -1.0

def test_five_point_affine_alignment():
    """Tests canonical 5-point affine transformation to 112x112."""
    aligner = FaceAligner()
    bgr_canvas = np.zeros((300, 300, 3), dtype=np.uint8)
    
    # Synthetic landmarks in 300x300 canvas
    landmarks = np.array([
        [100.0, 110.0],  # right eye
        [200.0, 108.0],  # left eye
        [150.0, 160.0],  # nose tip
        [110.0, 210.0],  # right mouth
        [190.0, 210.0]   # left mouth
    ], dtype=np.float32)

    aligned = aligner.align(bgr_canvas, landmarks)
    assert aligned.shape == (112, 112, 3)

    thumb_bytes = aligner.create_thumbnail_bytes(aligned)
    assert len(thumb_bytes) > 100
    assert thumb_bytes[:2] == b"\xff\xd8"  # JPEG Magic Bytes

def test_quality_blur_and_lighting_checks():
    """Validates separate, actionable quality checks."""
    sharp_img = np.zeros((200, 200, 3), dtype=np.uint8)
    cv2.rectangle(sharp_img, (50, 50), (150, 150), (255, 255, 255), -1)
    cv2.line(sharp_img, (0, 0), (200, 200), (128, 255, 0), 3)
    
    blur_score, is_blurry = check_blur(sharp_img)
    assert blur_score > settings.BLUR_THRESHOLD
    assert is_blurry is False

    # Blurry image
    blurry_img = cv2.GaussianBlur(sharp_img, (45, 45), 20.0)
    b_score, is_b = check_blur(blurry_img)
    assert is_b is True

    # Dark image
    dark_img = np.full((100, 100, 3), 10, dtype=np.uint8)
    brightness, valid_light = check_lighting(dark_img)
    assert brightness < settings.MIN_BRIGHTNESS
    assert valid_light is False

def test_quality_bounded_clipping_vs_severe_clipping():
    """Tests bounded detector-box overflow when landmarks remain visible."""
    img = np.full((300, 300, 3), 128, dtype=np.uint8)
    
    # 1. Bounded overflow: box slightly extends past left edge, but landmarks are strictly inside
    box_bounded = BoundingBox(x=-15.0, y=20.0, width=150.0, height=180.0)
    landmarks_inside = Landmarks5(
        right_eye=Point2D(x=20.0, y=70.0),
        left_eye=Point2D(x=80.0, y=70.0),
        nose_tip=Point2D(x=50.0, y=100.0),
        right_mouth=Point2D(x=30.0, y=140.0),
        left_mouth=Point2D(x=70.0, y=140.0)
    )
    metrics = validate_face_geometry_and_quality(img, box_bounded, landmarks_inside, strict_for_enrollment=False)
    assert metrics.is_clipping_valid is True

    # 2. Severe clipping: landmarks extend outside the canvas
    landmarks_outside = Landmarks5(
        right_eye=Point2D(x=-10.0, y=70.0),  # Landmark outside boundary!
        left_eye=Point2D(x=50.0, y=70.0),
        nose_tip=Point2D(x=20.0, y=100.0),
        right_mouth=Point2D(x=0.0, y=140.0),
        left_mouth=Point2D(x=40.0, y=140.0)
    )
    with pytest.raises(QualityException) as exc_info:
        validate_face_geometry_and_quality(img, box_bounded, landmarks_outside, strict_for_enrollment=True)
    assert exc_info.value.code == "FACE_CLIPPED"
