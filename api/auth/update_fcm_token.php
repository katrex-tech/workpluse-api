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

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

$userId = isset($input['user_id']) ? (int) $input['user_id'] : getAuthenticatedUserId(false);
$fcmToken = isset($input['fcm_token']) ? trim((string) $input['fcm_token']) : '';

if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

if (empty($fcmToken)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'FCM Token cannot be empty.',
        'data' => null,
    ], 422);
}

try {
    $stmt = $pdo->prepare('UPDATE users SET fcm_token = :token WHERE id = :id');
    $stmt->execute([
        'token' => $fcmToken,
        'id' => $userId,
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'FCM device token registered successfully.',
        'data' => [
            'user_id' => $userId,
            'fcm_token_length' => strlen($fcmToken),
        ],
    ]);
} catch (PDOException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
