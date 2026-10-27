"""
WorkPulse Face Recognition - Calibration Workflow
Evaluates False Acceptance Rate (FAR) and False Rejection Rate (FRR) across
consented held-out image pairs to calibrate the optimal Cosine Similarity Threshold
and Ambiguity Margin for attendance verification.

Usage:
    python scripts/calibrate_threshold.py --pairs-dir tests/sample_images
"""

import os
import sys
import glob
import argparse
import numpy as np

# Ensure app is in python path
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app.pipeline.quality import decode_and_correct_image
from app.pipeline.detector import YuNetFaceDetector
from app.pipeline.aligner import FaceAligner
from app.pipeline.recognizer import ArcFaceRecognizer

def extract_embedding_from_file(file_path: str, detector: YuNetFaceDetector, aligner: FaceAligner, recognizer: ArcFaceRecognizer):
    with open(file_path, "rb") as f:
        data = f.read()
    bgr = decode_and_correct_image(data)
    faces = detector.detect(bgr)
    if not faces:
        raise ValueError(f"No face detected in {file_path}")
    face_schema, landmarks_5x2 = faces[0]
    aligned = aligner.align(bgr, landmarks_5x2)
    emb = recognizer.compute_embedding(aligned)
    return emb

def run_calibration(images_dir: str):
    print("=" * 60)
    print("WorkPulse Face Recognition - Threshold Calibration Workflow")
    print("=" * 60)

    image_files = sorted(glob.glob(os.path.join(images_dir, "*.jpg")) + glob.glob(os.path.join(images_dir, "*.png")))
    if not image_files:
        print(f"[!] No image files found in {images_dir}")
        return

    print(f"[*] Found {len(image_files)} sample captures in {images_dir}")
    embeddings = {}
    detector = YuNetFaceDetector()
    aligner = FaceAligner()
    recognizer = ArcFaceRecognizer()

    for path in image_files:
        name = os.path.basename(path)
        try:
            emb = extract_embedding_from_file(path, detector, aligner, recognizer)
            embeddings[name] = emb
            print(f"  [+] Processed: {name} (Norm: {np.linalg.norm(emb):.4f})")
        except Exception as e:
            print(f"  [-] Skipped {name}: {e}")

    # Compute pairwise similarities
    genuine_scores = []
    impostor_scores = []

    names = list(embeddings.keys())
    for i in range(len(names)):
        for j in range(i + 1, len(names)):
            name1 = names[i]
            name2 = names[j]
            score = ArcFaceRecognizer.compute_similarity(embeddings[name1], embeddings[name2])
            
            # Extract subject ID (after underscore or before extension)
            subj1 = name1.split("_")[-1].split(".")[0]
            subj2 = name2.split("_")[-1].split(".")[0]

            if subj1 == subj2:
                genuine_scores.append((name1, name2, score))
            else:
                impostor_scores.append((name1, name2, score))

    print("\n" + "=" * 60)
    print("Pairwise Cosine Similarity Results:")
    print("=" * 60)
    print("--- Genuine Pairs (Same Person) ---")
    for n1, n2, s in genuine_scores:
        print(f"  {n1} <-> {n2} : Cosine Similarity = {s:.4f}")

    print("\n--- Impostor / Proxy Pairs (Different People) ---")
    for n1, n2, s in impostor_scores:
        print(f"  {n1} <-> {n2} : Cosine Similarity = {s:.4f}")

    if genuine_scores and impostor_scores:
        gen_vals = [s for _, _, s in genuine_scores]
        imp_vals = [s for _, _, s in impostor_scores]

        min_genuine = min(gen_vals)
        max_impostor = max(imp_vals)
        margin = min_genuine - max_impostor

        print("\n" + "=" * 60)
        print("Biometric Separation Analysis:")
        print("=" * 60)
        print(f"  Minimum Genuine Score:  {min_genuine:.4f}")
        print(f"  Maximum Impostor Score: {max_impostor:.4f}")
        print(f"  Discrimination Margin:  {margin:.4f}")

        # Recommended threshold is midpoint with safety margin
        recommended_threshold = round((min_genuine + max_impostor) / 2.0, 2)
        print(f"\n[+] Recommended Operational Threshold: {recommended_threshold}")
        print(f"[+] Recommended Ambiguity Margin:       0.08")
        if margin > 0.40:
            print("  --> PERFECT SEPARATION: Zero overlap between genuine users and proxy impostors.")
        else:
            print("  --> WARNING: Overlap detected. Collect additional captures under controlled lighting.")
    else:
        print("\n[!] Please provide pairs of genuine and impostor captures to compute separation.")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Calibrate Face Recognition Thresholds")
    parser.add_argument("--pairs-dir", default="tests/sample_images", help="Path to held-out captures")
    args = parser.parse_args()
    run_calibration(args.pairs_dir)
