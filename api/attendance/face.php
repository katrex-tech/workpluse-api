<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

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
            SELECT id, employee_id, full_name, face_registered, face_registered_at, 
                   registered_lat, registered_lng, registered_address,
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
            $update = $pdo->prepare('
                UPDATE users
                SET face_registered = 0,
                    face_data = NULL,
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

    try {
        $update = $pdo->prepare('
            UPDATE users
            SET face_registered = 1,
                face_data = :face_data,
                face_registered_at = :face_registered_at,
                registered_lat = :latitude,
                registered_lng = :longitude,
                registered_address = :address
            WHERE id = :id
        ');
        $update->execute([
            'face_data' => $faceData,
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
        $update = $pdo->prepare('
            UPDATE users
            SET face_registered = 0,
                face_data = NULL,
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
