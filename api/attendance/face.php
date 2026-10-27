<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/compreface_service.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $userId = getAuthenticatedUserId(false);
    if (!$userId || $userId < 1) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User ID is required.',
            'data' => null,
        ], 422);
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, employee_id, full_name, profile_photo, face_registered, face_data, face_registered_at, 
                   registered_lat, registered_lng, registered_address, compreface_image_id, compreface_subject,
                   face_consent_given, face_consent_at,
                   CASE WHEN face_data IS NOT NULL AND LENGTH(face_data) > 0 THEN 1 ELSE 0 END AS has_face_data
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'User not found.',
                'data' => null,
            ], 404);
        }

        $faceImageUrl = null;
        $biometricProfile = null;
        if (!empty($user['face_data'])) {
            $rawFaceData = trim($user['face_data']);
            if (str_starts_with($rawFaceData, '{')) {
                $decoded = json_decode($rawFaceData, true);
                if (is_array($decoded)) {
                    if (!empty($decoded['image_path'])) {
                        $faceImageUrl = formatMediaUrl($decoded['image_path']);
                    }
                    if (!empty($decoded['biometric_profile'])) {
                        $biometricProfile = $decoded['biometric_profile'];
                    }
                }
            } elseif (str_starts_with($rawFaceData, 'uploads/') || str_starts_with($rawFaceData, 'http')) {
                $faceImageUrl = formatMediaUrl($rawFaceData);
            } elseif (str_starts_with($rawFaceData, 'data:image')) {
                $faceImageUrl = $rawFaceData;
            }
        }
        if ($faceImageUrl === null && !empty($user['profile_photo'])) {
            $faceImageUrl = formatMediaUrl($user['profile_photo']);
        }

        // Check for pending face change request
        $pendingReqStmt = $pdo->prepare("SELECT id, reason, created_at FROM face_change_requests WHERE user_id = :id AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $pendingReqStmt->execute(['id' => $userId]);
        $pendingReq = $pendingReqStmt->fetch(PDO::FETCH_ASSOC);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Face registration details retrieved successfully.',
            'data' => [
                'user_id' => (int) $user['id'],
                'employee_id' => $user['employee_id'],
                'full_name' => $user['full_name'],
                'face_registered' => (bool) $user['face_registered'],
                'face_registered_at' => $user['face_registered_at'],
                'registered_lat' => $user['registered_lat'] !== null ? (float) $user['registered_lat'] : null,
                'registered_lng' => $user['registered_lng'] !== null ? (float) $user['registered_lng'] : null,
                'registered_address' => $user['registered_address'],
                'has_face_data' => (bool) $user['has_face_data'],
                'biometric_profile' => $biometricProfile,
                'compreface_image_id' => $user['compreface_image_id'] ?? null,
                'compreface_enrolled' => !empty($user['compreface_image_id']),
                'face_consent_given' => (bool) ($user['face_consent_given'] ?? false),
                'face_consent_at' => $user['face_consent_at'] ?? null,
                'face_image_url' => $faceImageUrl,
                'pending_face_change_request' => $pendingReq ? true : false,
                'pending_request_details' => $pendingReq ?: null,
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Failed to fetch face details: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to fetch face registration status.',
            'data' => null,
        ], 500);
    }
} elseif ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $input = [];
    if (!empty($rawBody)) {
        try {
            $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Request body must be valid JSON.',
                'data' => null,
            ], 400);
        }
    }

    $action = $input['action'] ?? $_GET['action'] ?? 'register';
    $userId = isset($input['user_id']) ? (int) $input['user_id'] : getAuthenticatedUserId(false);

    if (!$userId || $userId < 1) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User ID is required.',
            'data' => null,
        ], 422);
    }

    if ($action === 'delete') {
        try {
            $cfStmt = $pdo->prepare('SELECT compreface_subject, compreface_image_id FROM users WHERE id = :id LIMIT 1');
            $cfStmt->execute(['id' => $userId]);
            $oldRow = $cfStmt->fetch(PDO::FETCH_ASSOC);
            $subToDelete = $oldRow['compreface_subject'] ?? $oldRow['compreface_image_id'] ?? (string) $userId;

            if ($subToDelete) {
                try {
                    $cfService = new CompreFaceService($config);
                    $cfService->deleteFace($subToDelete);
                } catch (Exception $e) {
                    error_log('Failed to delete CompreFace face: ' . $e->getMessage());
                }
            }

            $update = $pdo->prepare('
                UPDATE users
                SET face_registered = 0,
                    face_data = NULL,
                    compreface_image_id = NULL,
                    compreface_subject = NULL,
                    face_consent_given = 0,
                    face_consent_at = NULL,
                    face_registered_at = NULL,
                    registered_lat = NULL,
                    registered_lng = NULL,
                    registered_address = NULL
                WHERE id = :id
            ');
            $update->execute(['id' => $userId]);

            sendJsonResponse([
                'status' => 'success',
                'message' => 'Registered face removed successfully.',
                'data' => [
                    'user_id' => $userId,
                    'face_registered' => false,
                ],
            ]);
        } catch (PDOException $e) {
            error_log('Failed to delete face: ' . $e->getMessage());
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Failed to remove face registration.',
                'data' => null,
            ], 500);
        }
    }

    // Default action is 'register'
    $faceData = $input['face_data'] ?? null;
    if (empty($faceData)) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Face biometric data is required for registration.',
            'data' => null,
        ], 422);
    }

    $latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
    $longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
    $address = isset($input['address']) && is_string($input['address']) ? trim($input['address']) : null;
    $now = date('Y-m-d H:i:s');

    // If faceData contains base64 image data, persist as image file
    $faceUploadDir = __DIR__ . '/../../uploads/face_photos';
    if (!is_dir($faceUploadDir)) {
        @mkdir($faceUploadDir, 0777, true);
    }

    $savedFacePath = null;
    if (is_string($faceData)) {
        if (preg_match('/^data:image\/(\w+);base64,/', $faceData, $matches)) {
            $imgRaw = substr($faceData, strpos($faceData, ',') + 1);
            $ext = strtolower($matches[1]) === 'png' ? 'png' : 'jpg';
            $decoded = base64_decode($imgRaw, true);
            if ($decoded !== false) {
                $fname = 'face_' . $userId . '_' . time() . '.' . $ext;
                if (file_put_contents($faceUploadDir . DIRECTORY_SEPARATOR . $fname, $decoded) !== false) {
                    $savedFacePath = 'uploads/face_photos/' . $fname;
                    $faceData = $savedFacePath;
                }
            }
        } elseif (strlen($faceData) > 500 && !str_starts_with($faceData, 'http') && !str_starts_with($faceData, 'uploads/')) {
            $decoded = base64_decode($faceData, true);
            if ($decoded !== false) {
                $fname = 'face_' . $userId . '_' . time() . '.jpg';
                if (file_put_contents($faceUploadDir . DIRECTORY_SEPARATOR . $fname, $decoded) !== false) {
                    $savedFacePath = 'uploads/face_photos/' . $fname;
                    $faceData = $savedFacePath;
                }
            }
        }
    }

    $cfSubject = (string) $userId;
    if ($savedFacePath && file_exists(__DIR__ . '/../../' . $savedFacePath)) {
        try {
            $cfService = new CompreFaceService($config);
            $cfService->addFace(__DIR__ . '/../../' . $savedFacePath, $cfSubject);
        } catch (Exception $e) {
            error_log('CompreFace enrollment in face.php warning: ' . $e->getMessage());
        }
    }

    try {
        $update = $pdo->prepare('
            UPDATE users
            SET face_registered = 1,
                face_data = :face_data,
                compreface_image_id = :cf_img_id,
                compreface_subject = :cf_sub,
                face_registered_at = :face_registered_at,
                registered_lat = :latitude,
                registered_lng = :longitude,
                registered_address = :address
            WHERE id = :id
        ');
        $update->execute([
            'face_data' => $faceData,
            'cf_img_id' => $cfSubject,
            'cf_sub' => $cfSubject,
            'face_registered_at' => $now,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address,
            'id' => $userId,
        ]);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Face registered successfully with location.',
            'data' => [
                'user_id' => $userId,
                'face_registered' => true,
                'face_registered_at' => $now,
                'registered_lat' => $latitude,
                'registered_lng' => $longitude,
                'registered_address' => $address,
                'face_image_url' => formatMediaUrl($faceData),
            ],
        ], 200);
    } catch (PDOException $e) {
        error_log('Failed to register face: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to register face: ' . $e->getMessage(),
            'data' => null,
        ], 500);
    }
} elseif ($method === 'DELETE') {
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : getAuthenticatedUserId(false);

    if (!$userId || $userId < 1) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User ID is required.',
            'data' => null,
        ], 422);
    }

    try {
        $cfStmt = $pdo->prepare('SELECT compreface_image_id FROM users WHERE id = :id LIMIT 1');
        $cfStmt->execute(['id' => $userId]);
        $oldImgId = $cfStmt->fetchColumn();
        if ($oldImgId) {
            try {
                $cfService = new CompreFaceService($config);
                $cfService->deleteFace($oldImgId);
            } catch (Exception $e) {
                error_log('Failed to delete CompreFace face: ' . $e->getMessage());
            }
        }

        $update = $pdo->prepare('
            UPDATE users
            SET face_registered = 0,
                face_data = NULL,
                compreface_image_id = NULL,
                compreface_subject = NULL,
                face_consent_given = 0,
                face_consent_at = NULL,
                face_registered_at = NULL,
                registered_lat = NULL,
                registered_lng = NULL,
                registered_address = NULL
            WHERE id = :id
        ');
        $update->execute(['id' => $userId]);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Registered face removed successfully.',
            'data' => [
                'user_id' => $userId,
                'face_registered' => false,
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Failed to delete face: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to remove face registration.',
            'data' => null,
        ], 500);
    }
} else {
    header('Allow: GET, POST, DELETE, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}
