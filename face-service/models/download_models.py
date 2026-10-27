"""
Reproducible weights setup script with pinned URLs and SHA-256 verification.
Downloads:
1. YuNet 5-landmark face detector: face_detection_yunet_2023mar.onnx (OpenCV Zoo)
2. ArcFace MobileFaceNet 512-D recognizer: w600k_mbf.onnx (InsightFace Buffalo_s)
"""
import os
import io
import hashlib
import zipfile
import httpx

WEIGHTS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "weights")
os.makedirs(WEIGHTS_DIR, exist_ok=True)

MODELS = {
    "face_detection_yunet_2023mar.onnx": {
        "url": "https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx",
        "sha256": "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
        "source": "OpenCV Zoo YuNet Face Detector",
        "type": "direct"
    },
    "w600k_mbf.onnx": {
        "url": "https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_s.zip",
        "sha256": "9cc6e4a75f0e2bf0b1aed94578f144d15175f357bdc05e815e5c4a02b319eb4f",
        "source": "InsightFace Buffalo_s MobileFaceNet ArcFace 512-D",
        "type": "zip_extract",
        "zip_target": "w600k_mbf.onnx"
    }
}

def verify_file_sha256(filepath: str, expected_hash: str) -> bool:
    if not os.path.exists(filepath):
        return False
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest().lower() == expected_hash.lower()

def main():
    print(f"[*] Checking and downloading pretrained model weights into: {WEIGHTS_DIR}")
    
    with httpx.Client(follow_redirects=True, timeout=120.0) as client:
        for filename, info in MODELS.items():
            out_path = os.path.join(WEIGHTS_DIR, filename)
            if verify_file_sha256(out_path, info["sha256"]):
                print(f"[+] {filename} already present and verified (SHA-256 match).")
                continue

            print(f"[*] Downloading {info['source']}...")
            if info["type"] == "direct":
                resp = client.get(info["url"])
                resp.raise_for_status()
                with open(out_path, "wb") as f:
                    f.write(resp.content)
            elif info["type"] == "zip_extract":
                print(f"    Streaming zip archive from {info['url']}...")
                resp = client.get(info["url"])
                resp.raise_for_status()
                with zipfile.ZipFile(io.BytesIO(resp.content)) as z:
                    for entry in z.namelist():
                        if entry.endswith(info["zip_target"]):
                            data = z.read(entry)
                            with open(out_path, "wb") as f:
                                f.write(data)
                            break

            if verify_file_sha256(out_path, info["sha256"]):
                print(f"[+] Successfully verified and installed {filename} (SHA256: {info['sha256']}).")
            else:
                raise RuntimeError(f"[-] Checksum verification failed for {filename}!")

    print("[*] All recognition weights are ready.")

if __name__ == "__main__":
    main()
