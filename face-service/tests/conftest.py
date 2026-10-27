import os
import io
import sys
import pytest
import numpy as np
import cv2
from PIL import Image

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from app.config import settings
from app.main import app
from app.database import init_db
from app.security import create_access_token

@pytest.fixture(autouse=True, scope="session")
def anyio_backend():
    return "asyncio"

@pytest.fixture(autouse=True)
async def setup_test_db():
    await init_db()
    yield

@pytest.fixture(scope="session")
def valid_jwt_token():
    return create_access_token(
        tenant_id="tenant_test",
        user_id="test_admin",
        scopes=["faces:detect", "faces:enroll", "faces:identify", "faces:manage"]
    )

@pytest.fixture(scope="session")
def tenant_b_jwt_token():
    return create_access_token(
        tenant_id="tenant_other",
        user_id="other_admin",
        scopes=["faces:detect", "faces:enroll", "faces:identify", "faces:manage"]
    )

@pytest.fixture(scope="session")
def limited_scope_token():
    return create_access_token(
        tenant_id="tenant_test",
        user_id="limited_user",
        scopes=["faces:detect"]  # Missing faces:enroll, faces:identify, faces:manage
    )

def create_synthetic_face_image_bytes(
    width: int = 320,
    height: int = 320,
    add_blur: bool = False,
    brightness: int = 128
) -> bytes:
    """
    Creates a realistic synthetic head-and-shoulders portrait with standard facial features
    to trigger OpenCV face detection and alignment.
    """
    img = np.full((height, width, 3), brightness, dtype=np.uint8)

    # Center coordinates
    cx, cy = width // 2, height // 2

    # Draw head (oval)
    cv2.ellipse(img, (cx, cy), (int(width * 0.28), int(height * 0.35)), 0, 0, 360, (180, 200, 220), -1)

    # Eyes
    eye_y = int(cy - height * 0.08)
    left_eye_x = int(cx - width * 0.12)
    right_eye_x = int(cx + width * 0.12)
    cv2.circle(img, (left_eye_x, eye_y), int(width * 0.04), (40, 40, 40), -1)
    cv2.circle(img, (right_eye_x, eye_y), int(width * 0.04), (40, 40, 40), -1)
    cv2.circle(img, (left_eye_x, eye_y), int(width * 0.015), (255, 255, 255), -1)
    cv2.circle(img, (right_eye_x, eye_y), int(width * 0.015), (255, 255, 255), -1)

    # Nose
    nose_y = int(cy + height * 0.03)
    pts = np.array([[cx, int(cy - height * 0.02)], [int(cx - width * 0.03), nose_y], [int(cx + width * 0.03), nose_y]])
    cv2.polylines(img, [pts], isClosed=True, color=(120, 140, 160), thickness=2)

    # Mouth
    mouth_y = int(cy + height * 0.15)
    cv2.ellipse(img, (cx, mouth_y), (int(width * 0.08), int(height * 0.03)), 0, 0, 180, (50, 60, 180), 3)

    if add_blur:
        img = cv2.GaussianBlur(img, (31, 31), 15.0)

    # Encode to JPEG
    _, encoded = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 95])
    return encoded.tobytes()

@pytest.fixture
def sample_face_bytes():
    path = os.path.join(os.path.dirname(__file__), "sample_images", "007_B.jpg")
    with open(path, "rb") as f:
        return f.read()

@pytest.fixture
def probe_same_person_bytes():
    path = os.path.join(os.path.dirname(__file__), "sample_images", "008_B.jpg")
    with open(path, "rb") as f:
        return f.read()

@pytest.fixture
def probe_different_person_bytes():
    path = os.path.join(os.path.dirname(__file__), "sample_images", "009_C.jpg")
    with open(path, "rb") as f:
        return f.read()

@pytest.fixture
def blurry_image_bytes():
    return create_synthetic_face_image_bytes(320, 320, add_blur=True)

@pytest.fixture
def dark_image_bytes():
    return create_synthetic_face_image_bytes(320, 320, brightness=20)
