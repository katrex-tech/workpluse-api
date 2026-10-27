import os
import sys
import time
import psutil
import numpy as np

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from app.config import settings
from app.pipeline.engine import FaceEngine

def run_benchmark(num_iterations: int = 50):
    print("=" * 60)
    print(f"=== {settings.SERVICE_NAME} Biometric Benchmark ===")
    print("=" * 60)

    proc = psutil.Process()
    mem_before = proc.memory_info().rss / (1024 * 1024)
    print(f"Initial Memory RSS: {mem_before:.2f} MB")

    engine = FaceEngine.get_instance()
    mem_after_load = proc.memory_info().rss / (1024 * 1024)
    print(f"Memory RSS After Model Load: {mem_after_load:.2f} MB (Delta: +{mem_after_load - mem_before:.2f} MB)")
    print(f"Model Checksum: {engine.recognizer.checksum}")
    print(f"Embedding Dimension: {settings.MODEL_EMBEDDING_DIM}")

    # Load test image
    sample_path = os.path.join(os.path.dirname(__file__), "..", "tests", "sample_images", "007_B.jpg")
    with open(sample_path, "rb") as f:
        img_bytes = f.read()

    # 1. Benchmark Detection & Alignment
    det_latencies = []
    rec_latencies = []
    e2e_latencies = []

    print(f"\nRunning {num_iterations} end-to-end iterations...")
    for i in range(num_iterations):
        t0 = time.perf_counter()

        # Step A: Detection & Alignment
        t_det_start = time.perf_counter()
        bgr_img, faces = engine._sync_detect(img_bytes, strict_for_enrollment=False)
        det_face, raw_landmarks = faces[0]
        aligned_bgr = engine.aligner.align(bgr_img, raw_landmarks)
        t_det_end = time.perf_counter()
        det_latencies.append((t_det_end - t_det_start) * 1000)

        # Step B: Deep Feature Extraction (ArcFace)
        t_rec_start = time.perf_counter()
        emb = engine.recognizer.compute_embedding(aligned_bgr)
        t_rec_end = time.perf_counter()
        rec_latencies.append((t_rec_end - t_rec_start) * 1000)

        t_total = time.perf_counter() - t0
        e2e_latencies.append(t_total * 1000)

    # 2. Benchmark Gallery Vector Search (10,000 enrolled faces in memory)
    print("\nBenchmarking Vector Search against 10,000 enrolled gallery faces...")
    gallery_matrix = np.random.randn(10000, 512).astype(np.float32)
    norms = np.linalg.norm(gallery_matrix, axis=1, keepdims=True)
    gallery_matrix = gallery_matrix / norms

    probe_vec = emb.copy()

    search_latencies = []
    for _ in range(100):
        t_s0 = time.perf_counter()
        scores = np.dot(gallery_matrix, probe_vec)
        top_idx = np.argmax(scores)
        search_latencies.append((time.perf_counter() - t_s0) * 1000)

    mem_final = proc.memory_info().rss / (1024 * 1024)

    print("\n" + "=" * 60)
    print("=== BENCHMARK PERFORMANCE RESULTS (CPU) ===")
    print("=" * 60)
    print(f"Face Detection & Alignment Latency:")
    print(f"  Mean:   {np.mean(det_latencies):.2f} ms")
    print(f"  P50:    {np.percentile(det_latencies, 50):.2f} ms")
    print(f"  P95:    {np.percentile(det_latencies, 95):.2f} ms")
    print(f"\nArcFace 512-D Feature Extraction Latency:")
    print(f"  Mean:   {np.mean(rec_latencies):.2f} ms")
    print(f"  P50:    {np.percentile(rec_latencies, 50):.2f} ms")
    print(f"  P95:    {np.percentile(rec_latencies, 95):.2f} ms")
    print(f"\nTotal End-to-End Pipeline Latency:")
    print(f"  Mean:   {np.mean(e2e_latencies):.2f} ms")
    print(f"  P50:    {np.percentile(e2e_latencies, 50):.2f} ms")
    print(f"  P95:    {np.percentile(e2e_latencies, 95):.2f} ms")
    print(f"  Throughput: {1000.0 / np.mean(e2e_latencies):.1f} frames/sec/worker")
    print(f"\nExact 1:10,000 Vector Search Latency:")
    print(f"  Mean:   {np.mean(search_latencies):.3f} ms")
    print(f"  P95:    {np.percentile(search_latencies, 95):.3f} ms")
    print(f"\nMemory Footprint:")
    print(f"  Final Process RSS: {mem_final:.2f} MB")
    print("=" * 60)

if __name__ == "__main__":
    run_benchmark(50)
