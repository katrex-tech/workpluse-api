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
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : getAuthenticatedUserId(false);

if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

// Ensure user exists
$userStmt = $pdo->prepare('SELECT id, profile_photo FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User not found.',
        'data' => null,
    ], 404);
}

$uploadDir = __DIR__ . '/../../uploads/profile_photos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$savedFilename = null;

// 1. Check for standard multipart file upload ($_FILES)
$file = $_FILES['photo'] ?? $_FILES['image'] ?? $_FILES['profile_photo'] ?? null;

if ($file && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Error occurred during file upload (code ' . $file['error'] . ').',
            'data' => null,
        ], 400);
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Profile image cannot exceed 10 MB in size.',
            'data' => null,
        ], 413);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Invalid image format. Allowed formats: JPEG, PNG, WebP, GIF.',
            'data' => null,
        ], 415);
    }

    $ext = $allowedTypes[$mimeType];
    $savedFilename = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $savedFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to save uploaded image to disk.',
            'data' => null,
        ], 500);
    }
} else {
    // 2. Fallback: Check for base64 JSON payload
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?: [];
    $base64Data = $input['image'] ?? $input['photo'] ?? $input['profile_photo'] ?? $_POST['image'] ?? null;

    if (!empty($base64Data) && is_string($base64Data)) {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            $ext = strtolower($type[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
        } else {
            $ext = 'jpg';
        }

        $decoded = base64_decode($base64Data, true);
        if ($decoded === false) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Invalid base64 image data.',
                'data' => null,
            ], 400);
        }

        $savedFilename = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $savedFilename;
        if (file_put_contents($destination, $decoded) === false) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Failed to save base64 image to disk.',
                'data' => null,
            ], 500);
        }
    } else {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No image file or base64 data provided in request.',
            'data' => null,
        ], 422);
    }
}

// Build relative path and full URL
$relativePath = 'uploads/profile_photos/' . $savedFilename;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '192.168.1.17';
$fullUrl = "$scheme://$host/workpulse-api/$relativePath";

try {
    // Optionally clean up old custom uploaded photo
    $oldPhoto = $user['profile_photo'] ?? null;
    if ($oldPhoto && str_starts_with($oldPhoto, 'uploads/profile_photos/')) {
        $oldFilePath = __DIR__ . '/../../' . $oldPhoto;
        if (is_file($oldFilePath)) {
            @unlink($oldFilePath);
        }
    }

    $updateStmt = $pdo->prepare('UPDATE users SET profile_photo = :photo WHERE id = :id');
    $updateStmt->execute([
        'photo' => $relativePath,
        'id' => $userId,
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Profile photo updated successfully.',
        'data' => [
            'user_id' => $userId,
            'profile_photo' => $fullUrl,
            'relative_path' => $relativePath,
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Failed to update user profile photo: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to save profile photo in database: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
