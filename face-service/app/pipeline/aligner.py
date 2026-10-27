import base64
from typing import Tuple, Optional
import numpy as np
import cv2

# Standard ArcFace canonical 5-landmark coordinates for 112x112 aligned crop:
# [right_eye, left_eye, nose_tip, right_mouth_corner, left_mouth_corner]
ARCFACE_REFERENCE_112 = np.array([
    [38.2946, 51.6963],  # Right eye
    [73.5318, 51.5014],  # Left eye
    [56.0252, 71.7366],  # Nose tip
    [41.5493, 92.3655],  # Right mouth corner
    [70.7299, 92.2041],  # Left mouth corner
], dtype=np.float32)

class FaceAligner:
    """
    Performs 5-landmark similarity/affine alignment to produce canonical 112x112 face crops,
    normalizes geometry, and generates compressed JPEG thumbnails.
    """
    def __init__(self, target_size: Tuple[int, int] = (112, 112)):
        self.target_size = target_size
        self.ref_pts = ARCFACE_REFERENCE_112

    def align(self, bgr_img: np.ndarray, raw_landmarks: np.ndarray) -> np.ndarray:
        """
        Warps the face region using partial affine (similarity transform)
        preserving facial feature proportions without stretching.
        """
        assert raw_landmarks.shape == (5, 2), "Landmarks must be of shape (5, 2)"
        
        # Estimate similarity transformation matrix
        transform_matrix, inliers = cv2.estimateAffinePartial2D(
            raw_landmarks.astype(np.float32),
            self.ref_pts,
            method=cv2.LMEDS
        )

        if transform_matrix is None:
            # Fallback to standard affine if similarity transform estimation fails
            transform_matrix = cv2.getAffineTransform(
                raw_landmarks[:3].astype(np.float32),
                self.ref_pts[:3]
            )

        aligned = cv2.warpAffine(
            bgr_img,
            transform_matrix,
            self.target_size,
            borderMode=cv2.BORDER_REFLECT101
        )
        return aligned

    def create_thumbnail_bytes(self, aligned_bgr: np.ndarray, quality: int = 92) -> bytes:
        """Encodes aligned face crop into high quality JPEG bytes."""
        success, encoded = cv2.imencode(".jpg", aligned_bgr, [cv2.IMWRITE_JPEG_QUALITY, quality])
        if not success:
            raise RuntimeError("Failed to encode aligned face thumbnail.")
        return encoded.tobytes()

    def create_thumbnail_base64(self, aligned_bgr: np.ndarray, quality: int = 85) -> str:
        """Encodes aligned face into base64 JPEG data URI."""
        raw_bytes = self.create_thumbnail_bytes(aligned_bgr, quality=quality)
        b64 = base64.b64encode(raw_bytes).decode("ascii")
        return f"data:image/jpeg;base64,{b64}"
