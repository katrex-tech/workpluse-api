<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/liveness_verification_service.php';

$userId = getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Authentication required to obtain a liveness challenge.',
        'data' => null,
    ], 401);
}

// Read body
$rawBody = file_get_contents('php://input');
$input = [];
if (!empty($rawBody)) {
    try {
        $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        // Fallback to query params
    }
}

$purpose = $input['purpose'] ?? $_GET['purpose'] ?? 'attendance';
if (!in_array($purpose, ['enrollment', 'attendance'], true)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid challenge purpose. Allowed values are "enrollment" or "attendance".',
        'data' => null,
    ], 422);
}

try {
    $service = new LivenessVerificationService($pdo, $config);
    $challenge = $service->issueChallenge($userId, $purpose);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Liveness challenge issued successfully.',
        'data' => [
            'nonce' => $challenge['nonce'],
            'actions' => $challenge['actions'],
            'expiresAt' => $challenge['expiresAt'],
            'purpose' => $purpose,
        ],
    ], 200);
} catch (Exception $e) {
    error_log('Failed to issue liveness challenge: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to generate liveness challenge.',
        'data' => null,
    ], 500);
}
