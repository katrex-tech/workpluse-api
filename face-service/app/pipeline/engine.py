import asyncio
import time
import base64
from concurrent.futures import ThreadPoolExecutor
from typing import List, Tuple, Optional, Dict, Any
import numpy as np
import cv2

from app.config import settings
from app.schemas import DetectedFace, IdentificationResult, CandidateMatch
from app.pipeline.quality import decode_and_correct_image, QualityException
from app.pipeline.detector import YuNetFaceDetector
from app.pipeline.aligner import FaceAligner
from app.pipeline.recognizer import ArcFaceRecognizer

class FaceEngine:
    _instance: Optional["FaceEngine"] = None

    def __init__(self):
        self.detector = YuNetFaceDetector()
        self.aligner = FaceAligner()
        self.recognizer = ArcFaceRecognizer()
        self.thread_pool = ThreadPoolExecutor(max_workers=settings.INFERENCE_CONCURRENCY)
        self.semaphore = asyncio.Semaphore(settings.INFERENCE_CONCURRENCY)

    @classmethod
    def get_instance(cls) -> "FaceEngine":
        if cls._instance is None:
            cls._instance = cls()
        return cls._instance

    def _sync_detect(self, image_bytes: bytes, strict_for_enrollment: bool) -> Tuple[np.ndarray, List[Tuple[DetectedFace, np.ndarray]]]:
        bgr_img = decode_and_correct_image(image_bytes)
        faces = self.detector.detect(bgr_img, strict_for_enrollment=strict_for_enrollment)
        return bgr_img, faces

    async def detect_faces(self, image_bytes: bytes, strict_for_enrollment: bool = False) -> Tuple[np.ndarray, List[Tuple[DetectedFace, np.ndarray]]]:
        async with self.semaphore:
            loop = asyncio.get_running_loop()
            return await loop.run_in_executor(
                self.thread_pool,
                self._sync_detect,
                image_bytes,
                strict_for_enrollment
            )

    def _sync_enrollment_pipeline(self, image_bytes: bytes) -> Tuple[bytes, np.ndarray, DetectedFace]:
        bgr_img, faces = self._sync_detect(image_bytes, strict_for_enrollment=True)
        if len(faces) == 0:
            raise QualityException(
                code="NO_FACE_DETECTED",
                message="No face detected in the enrollment image.",
                actionable_remedy="Ensure your face is clearly visible, well lit, and centered in front of the camera."
            )
        if len(faces) > 1:
            raise QualityException(
                code="MULTIPLE_FACES_DETECTED",
                message=f"Enrollment requires exactly one face, but {len(faces)} faces were detected.",
                actionable_remedy="Please take the photo alone without anyone else in the frame."
            )

        detected_face, raw_landmarks = faces[0]

        # 5-point alignment
        aligned_bgr = self.aligner.align(bgr_img, raw_landmarks)

        # Compute 512-D L2-normalized embedding
        embedding = self.recognizer.compute_embedding(aligned_bgr)

        # Generate JPEG thumbnail
        thumbnail_bytes = self.aligner.create_thumbnail_bytes(aligned_bgr, quality=92)

        return thumbnail_bytes, embedding, detected_face

    async def process_enrollment(self, image_bytes: bytes) -> Tuple[bytes, np.ndarray, DetectedFace]:
        async with self.semaphore:
            loop = asyncio.get_running_loop()
            return await loop.run_in_executor(
                self.thread_pool,
                self._sync_enrollment_pipeline,
                image_bytes
            )

    def _sync_extract_probe_faces(self, image_bytes: bytes) -> List[Dict[str, Any]]:
        bgr_img, faces = self._sync_detect(image_bytes, strict_for_enrollment=False)
        probes = []
        for detected_face, raw_landmarks in faces:
            # 5-point alignment
            aligned_bgr = self.aligner.align(bgr_img, raw_landmarks)
            # Embedding
            embedding = self.recognizer.compute_embedding(aligned_bgr)
            # Scanned preview data URI
            preview_b64 = self.aligner.create_thumbnail_base64(aligned_bgr, quality=80)

            probes.append({
                "detected_face": detected_face,
                "embedding": embedding,
                "preview_base64": preview_b64
            })
        return probes

    async def extract_probe_faces(self, image_bytes: bytes) -> List[Dict[str, Any]]:
        async with self.semaphore:
            loop = asyncio.get_running_loop()
            return await loop.run_in_executor(
                self.thread_pool,
                self._sync_extract_probe_faces,
                image_bytes
            )

    @staticmethod
    def evaluate_matching_decision(
        candidates: List[Dict[str, Any]],
        threshold: float,
        margin: float
    ) -> Tuple[str, Optional[str], Optional[CandidateMatch], Optional[CandidateMatch]]:
        """
        Server-controlled matching policy:
        Evaluates candidate similarity scores, runner-up margin, and ambiguity.
        Returns: (decision, matched_user_id, closest_candidate, runner_up)
        """
        if not candidates:
            return "unknown", None, None, None

        top_cand_data = candidates[0]
        top_cand = CandidateMatch(
            user_id=str(top_cand_data["user_id"]),
            score=round(float(top_cand_data["score"]), 4),
            enrolled_at=top_cand_data.get("enrolled_at"),
            thumbnail_url=top_cand_data.get("thumbnail_url")
        )

        runner_up = None
        if len(candidates) > 1:
            r_data = candidates[1]
            runner_up = CandidateMatch(
                user_id=str(r_data["user_id"]),
                score=round(float(r_data["score"]), 4),
                enrolled_at=r_data.get("enrolled_at"),
                thumbnail_url=r_data.get("thumbnail_url")
            )

        # Evaluate threshold
        if top_cand.score < threshold:
            return "unknown", None, top_cand, runner_up

        # Evaluate ambiguity with runner-up
        if runner_up is not None:
            score_diff = top_cand.score - runner_up.score
            if score_diff < margin:
                return "ambiguous", None, top_cand, runner_up

        # Accepted match
        return "matched", top_cand.user_id, top_cand, runner_up
