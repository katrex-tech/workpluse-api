import os
from typing import List, Tuple, Optional
import numpy as np
import cv2
from app.config import settings
from app.schemas import DetectedFace, BoundingBox, Landmarks5, Point2D
from app.pipeline.quality import validate_face_geometry_and_quality

class YuNetFaceDetector:
    def __init__(self, model_path: Optional[str] = None):
        self.model_path = model_path or os.path.join(settings.WEIGHTS_DIR, "face_detection_yunet_2023mar.onnx")
        if not os.path.exists(self.model_path):
            raise FileNotFoundError(
                f"Face detector model not found at {self.model_path}. "
                f"Please run 'python models/download_models.py' first."
            )
        self.detector = cv2.FaceDetectorYN.create(
            model=self.model_path,
            config="",
            input_size=(320, 320),
            score_threshold=settings.MIN_DET_CONFIDENCE,
            nms_threshold=0.3,
            top_k=5000
        )

    def detect(
        self,
        bgr_img: np.ndarray,
        strict_for_enrollment: bool = False
    ) -> List[Tuple[DetectedFace, np.ndarray]]:
        """
        Detects all faces in the BGR image.
        Returns a list of tuples: (DetectedFaceSchema, raw_5x2_landmarks_ndarray).
        """
        h, w = bgr_img.shape[:2]
        self.detector.setInputSize((w, h))

        retval, faces = self.detector.detect(bgr_img)
        if retval is None or faces is None or len(faces) == 0:
            return []

        results = []
        for face_row in faces:
            # face_row: [x, y, w, h, x_re, y_re, x_le, y_le, x_nt, y_nt, x_rcm, y_rcm, x_lcm, y_lcm, score]
            x, y, width, height = float(face_row[0]), float(face_row[1]), float(face_row[2]), float(face_row[3])
            score = float(face_row[14])

            # 5 Landmarks in image coordinate space:
            # right eye, left eye, nose tip, right mouth, left mouth
            raw_landmarks = np.array([
                [face_row[4], face_row[5]],    # right eye
                [face_row[6], face_row[7]],    # left eye
                [face_row[8], face_row[9]],    # nose tip
                [face_row[10], face_row[11]],  # right mouth corner
                [face_row[12], face_row[13]],  # left mouth corner
            ], dtype=np.float32)

            box_schema = BoundingBox(
                x=round(x, 1),
                y=round(y, 1),
                width=round(width, 1),
                height=round(height, 1)
            )

            landmarks_schema = Landmarks5(
                right_eye=Point2D(x=round(float(raw_landmarks[0, 0]), 1), y=round(float(raw_landmarks[0, 1]), 1)),
                left_eye=Point2D(x=round(float(raw_landmarks[1, 0]), 1), y=round(float(raw_landmarks[1, 1]), 1)),
                nose_tip=Point2D(x=round(float(raw_landmarks[2, 0]), 1), y=round(float(raw_landmarks[2, 1]), 1)),
                right_mouth=Point2D(x=round(float(raw_landmarks[3, 0]), 1), y=round(float(raw_landmarks[3, 1]), 1)),
                left_mouth=Point2D(x=round(float(raw_landmarks[4, 0]), 1), y=round(float(raw_landmarks[4, 1]), 1))
            )

            # Perform quality, geometry, blur, lighting, pose & clipping checks
            quality_metrics = validate_face_geometry_and_quality(
                bgr_img=bgr_img,
                box=box_schema,
                landmarks=landmarks_schema,
                strict_for_enrollment=strict_for_enrollment
            )

            detected_face = DetectedFace(
                box=box_schema,
                confidence=round(score, 3),
                landmarks=landmarks_schema,
                quality=quality_metrics
            )

            results.append((detected_face, raw_landmarks))

        return results
