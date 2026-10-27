# CompreFace Self-Hosted Deployment Guide for WorkPulse

Exadel CompreFace is a free, open-source facial recognition and verification system that runs in Docker.

## 1. Quick Start

1. Install [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows/macOS) or Docker Engine with Docker Compose (Linux).
2. Open terminal in `c:\xampp\htdocs\workpulse-api\compreface`:
   ```bash
   cp .env.example .env
   docker compose up -d
   ```
3. Open your browser to the CompreFace console at:
   `http://localhost:8000`

---

## 2. Setting Up the Face Recognition Service

1. **Sign Up / Log In**: Register the first administrator account in the CompreFace web UI.
2. **Create an Application**: Click **Create Application**, name it `WorkPulse-HRMS`.
3. **Create Face Recognition Service**:
   - Inside the `WorkPulse-HRMS` application, click **Create Service**.
   - Choose **Recognition** service type (do NOT select Detection or Verification-only; Recognition provides subject collection + verification).
   - Name the service `Employee Biometrics`.
4. **Copy API Key**:
   - Copy the generated API Key (UUID format, e.g. `c7b6f790-3498-4e58-9a3d-a5113d072f9a`).

---

## 3. Configuring the WorkPulse PHP Backend

In your PHP environment (`c:\xampp\htdocs\workpulse-api\config\config.php` or server environment variables):

```php
'compreface' => [
    'url' => getenv('COMPREFACE_URL') ?: 'http://localhost:8000',
    'api_key' => getenv('COMPREFACE_API_KEY') ?: 'YOUR_COMPREFACE_API_KEY_HERE',
    'similarity_threshold' => 0.85, // 0.85 (85%) similarity threshold
    'timeout' => 15,
],
```

Or set in Windows / Apache environment:
- `COMPREFACE_URL=http://localhost:8000`
- `COMPREFACE_API_KEY=YOUR_COPIED_KEY`
- `COMPREFACE_THRESHOLD=0.85`

---

## 4. How Backend Interacts with CompreFace

The WorkPulse PHP backend communicates server-to-server with CompreFace:

1. **Face Enrollment**:
   `POST http://localhost:8000/api/v1/recognition/faces?subject={opaque_subject_id}`
   - Header: `x-api-key: {COMPREFACE_API_KEY}`
   - Form-Data: `file=@/path/to/neutral_reference.jpg`
   - Returns: `{"image_id": "UUID", "subject": "emp_123_abc"}`
   - PHP stores `image_id` in `users.compreface_image_id`.

2. **Attendance Verification**:
   `POST http://localhost:8000/api/v1/recognition/faces/{compreface_image_id}/verify`
   - Header: `x-api-key: {COMPREFACE_API_KEY}`
   - Form-Data: `file=@/path/to/live_neutral_capture.jpg`
   - Returns:
     ```json
     {
       "result": [
         {
           "similarity": 0.9642,
           "subject": "emp_123_abc",
           "box": { ... }
         }
       ]
     }
     ```
   - If `result` is empty $\rightarrow$ no face found.
   - If `count(result) > 1` $\rightarrow$ multiple faces found (rejected).
   - If `similarity >= similarity_threshold` $\rightarrow$ verified!

3. **Face Removal**:
   `DELETE http://localhost:8000/api/v1/recognition/faces/{compreface_image_id}`
   - Header: `x-api-key: {COMPREFACE_API_KEY}`

---

## 5. Security & Network Considerations

- **Server-to-Server Only**: The CompreFace API key and port (8000) must NEVER be exposed directly to mobile clients or the public internet. Only your backend server calls CompreFace.
- **Fail Closed**: If CompreFace is unreachable, the system rejects verification immediately (`SERVICE_UNAVAILABLE`).
- **Data Retention**: Set `SAVE_IMAGES_TO_DB=false` in `.env` so CompreFace only stores numerical feature embeddings rather than raw photos.
