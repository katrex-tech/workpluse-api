<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/compreface_service.php';

// 1. Authenticated User Check
$currentUser = getAuthenticatedUser(false);
$authUserId = $currentUser ? (int) $currentUser['id'] : getAuthenticatedUserId(false);

if (!$authUserId || $authUserId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unauthorized. Authentication required for face enrollment.',
        'data' => null,
    ], 401);
}

// Target user ID check (Prevent registering for other employees unless admin/hr)
$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : $authUserId;
if ($targetUserId !== $authUserId) {
    $userRole = strtolower($currentUser['role'] ?? '');
    if ($userRole !== 'admin' && $userRole !== 'super_admin' && $userRole !== 'hr') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Unauthorized. Employees cannot enroll or replace face records for other users.',
            'data' => null,
        ], 403);
    }
}

// 2. Fetch User & Existing Enrollment Status
$stmt = $pdo->prepare('SELECT id, full_name, employee_id, face_registered, compreface_image_id, compreface_subject FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Target user account not found.',
        'data' => null,
    ], 404);
}

// Check for force replacement
$forceReplace = isset($_POST['force_replace']) && ($_POST['force_replace'] === '1' || $_POST['force_replace'] === 'true');
if (!empty($targetUser['face_registered']) && !empty($targetUser['compreface_subject']) && !$forceReplace) {
    sendJsonResponse([
        'status' => 'error',
        'error_code' => 'FACE_ALREADY_REGISTERED',
        'message' => 'Face biometrics are already registered for this employee. Overwriting is blocked.',
        'data' => [
            'user_id' => $targetUserId,
            'face_registered' => true,
        ],
    ], 409);
}

// 3. Consent Verification
$consent = $_POST['consent'] ?? 'true';
if ($consent !== '1' && $consent !== 'true' && $consent !== true && $consent !== 1) {
    sendJsonResponse([
        'status' => 'error',
        'error_code' => 'CONSENT_REQUIRED',
        'message' => 'Employee consent for facial biometric processing is required before enrollment.',
        'data' => null,
    ], 422);
}

// 4. Extract Face Image (Multipart file or Base64)
$faceUploadDir = __DIR__ . '/../../uploads/face_photos';
if (!is_dir($faceUploadDir)) {
    @mkdir($faceUploadDir, 0777, true);
}

$tempFilePath = null;
$savedRelativePath = null;
$uploadedFile = $_FILES['file'] ?? $_FILES['image'] ?? $_FILES['face_image'] ?? null;

if ($uploadedFile && !empty($uploadedFile['tmp_name']) && is_uploaded_file($uploadedFile['tmp_name'])) {
    $ext = pathinfo($uploadedFile['name'] ?? 'face.jpg', PATHINFO_EXTENSION) ?: 'jpg';
    $fileName = 'face_' . $targetUserId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $destPath = $faceUploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
        $tempFilePath = $destPath;
        $savedRelativePath = 'uploads/face_photos/' . $fileName;
    }
} elseif (!empty($_POST['face_image']) || !empty($_POST['face_data'])) {
    $rawBase64 = $_POST['face_image'] ?? $_POST['face_data'];
    if (preg_match('/^data:image\/(\w+);base64,/', $rawBase64, $matches)) {
        $rawBase64 = substr($rawBase64, strpos($rawBase64, ',') + 1);
    }
    $decoded = base64_decode($rawBase64, true);
    if ($decoded !== false) {
        $fileName = 'face_' . $targetUserId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
        $destPath = $faceUploadDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($destPath, $decoded) !== false) {
            $tempFilePath = $destPath;
            $savedRelativePath = 'uploads/face_photos/' . $fileName;
        }
    }
}

if (!$tempFilePath || !file_exists($tempFilePath)) {
    sendJsonResponse([
        'status' => 'error',
        'error_code' => 'IMAGE_REQUIRED',
        'message' => 'A clear face photograph is required for biometric enrollment.',
        'data' => null,
    ], 422);
}

// 5. Enroll in ArcFace CompreFace Microservice
$compreFaceService = new CompreFaceService($config);
$subjectId = (string) $targetUserId;

// If previously enrolled in microservice and force_replace, delete old enrollment first
if ($forceReplace) {
    try {
        $compreFaceService->deleteFace($subjectId);
    } catch (Exception $e) {
        // Ignored if wasn't present
    }
}

$cfResult = $compreFaceService->addFace($tempFilePath, $subjectId);

if (!$cfResult['success']) {
    // Biometric quality or model failure
    $errCode = $cfResult['code'] ?? 'ENROLLMENT_FAILED';
    $errMsg = $cfResult['error'] ?? 'Biometric enrollment failed.';
    $actionable = $cfResult['actionable_remedy'] ?? 'Please ensure face is centered, well-lit, and unobstructed.';

    sendJsonResponse([
        'status' => 'error',
        'error_code' => $errCode,
        'message' => $errMsg,
        'actionable_remedy' => $actionable,
        'data' => null,
    ], $errCode === 'USER_ALREADY_ENROLLED' ? 409 : 422);
}

// 6. Update MySQL User Record with Enrolled Face
$now = date('Y-m-d H:i:s');
$faceDataJson = json_encode([
    'image_path' => $savedRelativePath,
    'enrolled_at' => $now,
    'model_checksum' => $cfResult['model_checksum'] ?? null,
    'thumbnail_url' => $cfResult['thumbnail_url'] ?? null,
], JSON_UNESCAPED_SLASHES);

try {
    $updateStmt = $pdo->prepare('
        UPDATE users
        SET face_registered = 1,
            face_data = :face_data,
            compreface_image_id = :cf_img_id,
            compreface_subject = :cf_sub,
            face_consent_given = 1,
            face_consent_at = :consent_at,
            face_registered_at = :reg_at
        WHERE id = :id
    ');
    $updateStmt->execute([
        'face_data' => $faceDataJson,
        'cf_img_id' => $subjectId,
        'cf_sub' => $subjectId,
        'consent_at' => $now,
        'reg_at' => $now,
        'id' => $targetUserId,
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Face registered successfully in biometric system.',
        'data' => [
            'user_id' => $targetUserId,
            'face_registered' => true,
            'face_registered_at' => $now,
            'face_image_url' => formatMediaUrl($savedRelativePath),
            'thumbnail_url' => $cfResult['thumbnail_url'] ?? null,
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Database error updating face record: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to save face enrollment to database.',
        'data' => null,
    ], 500);
}
