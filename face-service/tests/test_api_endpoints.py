import os
import pytest
import httpx
from app.main import app
from app.security import create_access_token, create_thumbnail_token

@pytest.mark.asyncio
async def test_health_endpoints():
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Live
        resp_live = await client.get("/health/live")
        assert resp_live.status_code == 200
        data_live = resp_live.json()
        assert data_live["status"] == "ok"
        assert "version" in data_live

        # Ready
        resp_ready = await client.get("/health/ready")
        assert resp_ready.status_code == 200
        data_ready = resp_ready.json()
        assert data_ready["status"] == "ok"
        assert data_ready["model_loaded"] is True
        assert data_ready["database_connected"] is True

@pytest.mark.asyncio
async def test_unauthorized_access():
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Detect without auth
        resp = await client.post("/v1/faces/detect")
        assert resp.status_code == 401

        # Enroll without auth
        resp = await client.post("/v1/users/emp_1/face")
        assert resp.status_code == 401

@pytest.mark.asyncio
async def test_insufficient_scope_forbidden(limited_scope_token):
    headers = {"Authorization": f"Bearer {limited_scope_token}"}
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Attempt to access gallery management with only 'faces:detect' scope
        resp = await client.get("/v1/enrollments", headers=headers)
        assert resp.status_code == 403

@pytest.mark.asyncio
async def test_enrollment_consent_validation(valid_jwt_token, sample_face_bytes):
    headers = {"Authorization": f"Bearer {valid_jwt_token}"}
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Submit enrollment with consent=false
        files = {"file": ("face.jpg", sample_face_bytes, "image/jpeg")}
        data = {"consent": "false"}
        resp = await client.post("/v1/users/emp_consent_test/face", headers=headers, files=files, data=data)
        assert resp.status_code == 422
        body = resp.json()
        assert body["error"]["code"] == "CONSENT_REQUIRED"

@pytest.mark.asyncio
async def test_enrollment_uniqueness_conflict_and_idempotency(valid_jwt_token, sample_face_bytes):
    headers = {
        "Authorization": f"Bearer {valid_jwt_token}",
        "Idempotency-Key": "idemp_test_key_001"
    }
    user_id = "emp_conflict_99"

    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # 1. Clean up user if existing
        await client.delete(f"/v1/users/{user_id}/face", headers=headers)

        # 2. First enrollment
        files = {"file": ("face.jpg", sample_face_bytes, "image/jpeg")}
        data = {"consent": "true"}
        resp1 = await client.post(f"/v1/users/{user_id}/face", headers=headers, files=files, data=data)
        assert resp1.status_code == 201
        data1 = resp1.json()
        assert data1["status"] == "enrolled"
        assert data1["user_id"] == user_id

        # 3. Idempotent retry with same idempotency key succeeds
        files_retry = {"file": ("face.jpg", sample_face_bytes, "image/jpeg")}
        resp_idemp = await client.post(f"/v1/users/{user_id}/face", headers=headers, files=files_retry, data=data)
        assert resp_idemp.status_code in [200, 201]

        # 4. Duplicate enrollment with DIFFERENT idempotency key -> HTTP 409 Conflict!
        headers_new = {
            "Authorization": f"Bearer {valid_jwt_token}",
            "Idempotency-Key": "different_key_002"
        }
        files_dup = {"file": ("face.jpg", sample_face_bytes, "image/jpeg")}
        resp_conflict = await client.post(f"/v1/users/{user_id}/face", headers=headers_new, files=files_dup, data=data)
        assert resp_conflict.status_code == 409
        body_conflict = resp_conflict.json()
        assert body_conflict["error"]["code"] == "ALREADY_ENROLLED"

@pytest.mark.asyncio
async def test_gallery_pagination_and_count(valid_jwt_token):
    headers = {"Authorization": f"Bearer {valid_jwt_token}"}
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        resp_count = await client.get("/v1/enrollments/count", headers=headers)
        assert resp_count.status_code == 200
        count_data = resp_count.json()
        assert "total_active_enrollments" in count_data

        resp_list = await client.get("/v1/enrollments?page=1&page_size=10", headers=headers)
        assert resp_list.status_code == 200
        list_data = resp_list.json()
        assert "items" in list_data
        assert "total" in list_data

@pytest.mark.asyncio
async def test_protected_thumbnail_access(valid_jwt_token):
    user_id = "emp_conflict_99"
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Access with invalid token -> 401
        resp_bad = await client.get(f"/v1/users/{user_id}/face/thumbnail?token=invalid_signature&tenant_id=tenant_test")
        assert resp_bad.status_code == 401

        # Access with valid HMAC signed token -> 200 image/jpeg
        valid_thumb_token = create_thumbnail_token("tenant_test", user_id)
        resp_good = await client.get(f"/v1/users/{user_id}/face/thumbnail?token={valid_thumb_token}&tenant_id=tenant_test")
        assert resp_good.status_code == 200
        assert resp_good.headers["content-type"] == "image/jpeg"

@pytest.mark.asyncio
async def test_tenant_isolation(valid_jwt_token, tenant_b_jwt_token, sample_face_bytes):
    """Verifies that faces enrolled under Tenant A are strictly isolated from Tenant B."""
    user_id = "emp_isolated_01"
    headers_a = {"Authorization": f"Bearer {valid_jwt_token}"}
    headers_b = {"Authorization": f"Bearer {tenant_b_jwt_token}"}

    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Enroll in Tenant A
        files = {"file": ("face.jpg", sample_face_bytes, "image/jpeg")}
        await client.post(f"/v1/users/{user_id}/face", headers=headers_a, files=files, data={"consent": "true"})

        # Query in Tenant B -> Must return 404 (Not Found)
        resp_b = await client.get(f"/v1/users/{user_id}/face", headers=headers_b)
        assert resp_b.status_code == 404

        # Clean up in Tenant A
        await client.delete(f"/v1/users/{user_id}/face", headers=headers_a)

@pytest.mark.asyncio
async def test_identify_genuine_face_and_proxy_rejection(
    valid_jwt_token, sample_face_bytes, probe_same_person_bytes, probe_different_person_bytes
):
    """
    Comprehensive Real-Face Biometric Verification Test:
    1. Enrolls Person B (007_B.jpg)
    2. Probes with Person B (008_B.jpg) -> Must be 'matched' with score >= 0.65
    3. Probes with Person C (009_C.jpg - Proxy Attempt) -> Must be 'unknown' with matched_user_id=None
    """
    user_id = "emp_genuine_b"
    headers = {"Authorization": f"Bearer {valid_jwt_token}"}

    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://test") as client:
        # Reset user
        await client.delete(f"/v1/users/{user_id}/face", headers=headers)

        # 1. Enroll Person B
        files_enroll = {"file": ("enroll_b.jpg", sample_face_bytes, "image/jpeg")}
        enroll_res = await client.post(
            f"/v1/users/{user_id}/face",
            headers=headers,
            files=files_enroll,
            data={"consent": "true"}
        )
        assert enroll_res.status_code == 201

        # 2. Genuine verification with 008_B.jpg (targeted 1:1 verification for attendance)
        files_genuine = {"file": ("probe_same.jpg", probe_same_person_bytes, "image/jpeg")}
        genuine_res = await client.post(
            "/v1/faces/identify",
            headers=headers,
            files=files_genuine,
            data={"target_user_id": user_id}
        )
        assert genuine_res.status_code == 200
        genuine_data = genuine_res.json()
        assert genuine_data["decision"] == "matched"
        assert genuine_data["matched_user_id"] == user_id
        assert genuine_data["closest_candidate"]["score"] >= 0.65
        assert genuine_data["liveness"] == "not_evaluated"

        # 3. Proxy attack verification with 009_C.jpg (Friend's / Different Face)
        files_proxy = {"file": ("probe_proxy.jpg", probe_different_person_bytes, "image/jpeg")}
        proxy_res = await client.post(
            "/v1/faces/identify",
            headers=headers,
            files=files_proxy,
            data={"target_user_id": user_id}
        )
        assert proxy_res.status_code == 200
        proxy_data = proxy_res.json()
        # PROXY MUST BE CAUGHT AND REJECTED!
        assert proxy_data["decision"] == "unknown"
        assert proxy_data["matched_user_id"] is None
        assert proxy_data["closest_candidate"]["score"] < 0.65

        # 4. Clean up
        await client.delete(f"/v1/users/{user_id}/face", headers=headers)

