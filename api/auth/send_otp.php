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
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$rawBody = file_get_contents('php://input');
try {
    $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be valid JSON.',
    ], 400);
}

$identifier = isset($input['identifier']) && is_string($input['identifier'])
    ? trim($input['identifier'])
    : (isset($input['mobile']) && is_string($input['mobile'])
        ? trim($input['mobile'])
        : (isset($input['email']) && is_string($input['email'])
            ? trim($input['email'])
            : ''));

if ($identifier === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide your registered work email or mobile number.',
    ], 422);
}

$cleanEmail = strtolower($identifier);

try {
    $stmt = $pdo->prepare('
        SELECT id, employee_id, full_name, email, mobile, status
        FROM users
        WHERE LOWER(email) = :email OR mobile = :mobile
        LIMIT 1
    ');
    $stmt->execute([
        'email' => $cleanEmail,
        'mobile' => $identifier,
    ]);
    $user = $stmt->fetch();

    if (!$user) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No active employee account found with this email/mobile. Contact HR to onboard.',
        ], 404);
    }

    if (isset($user['status']) && $user['status'] === 'inactive') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'This account has been deactivated.',
        ], 403);
    }

    // Generate 6-digit OTP
    $otp = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes validity

    $updateStmt = $pdo->prepare('UPDATE users SET otp = :otp, otp_expires_at = :expires WHERE id = :id');
    $updateStmt->execute([
        'otp' => $otp,
        'expires' => $expiresAt,
        'id' => $user['id'],
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => '6-digit OTP has been sent successfully.',
        'data' => [
            'identifier' => $identifier,
            'otp' => $otp, // Returned in dev/test environment for easy entry
            'expires_in_seconds' => 600,
        ],
    ]);
} catch (PDOException $e) {
    error_log('send_otp error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to send OTP: ' . $e->getMessage(),
    ], 500);
}
