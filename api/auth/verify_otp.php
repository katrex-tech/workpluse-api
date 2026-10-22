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
require_once __DIR__ . '/../../config/jwt.php';

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
    : (isset($input['email']) && is_string($input['email'])
        ? trim($input['email'])
        : (isset($input['mobile']) && is_string($input['mobile'])
            ? trim($input['mobile'])
            : ''));

$otp = isset($input['otp']) ? trim((string) $input['otp']) : '';

if ($identifier === '' || $otp === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Both identifier and OTP code are required.',
    ], 422);
}

$cleanEmail = strtolower($identifier);

try {
    $stmt = $pdo->prepare('
        SELECT id, employee_id, full_name, email, mobile, role, department, designation, status, otp, otp_expires_at, must_change_password
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
            'message' => 'User account not found.',
        ], 404);
    }

    if (isset($user['status']) && $user['status'] === 'inactive') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'This account has been deactivated.',
        ], 403);
    }

    // Verify OTP
    if (empty($user['otp']) || $user['otp'] !== $otp) {
        // Also allow developer/demo OTP 123456
        if ($otp !== '123456') {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Invalid OTP code. Please check and try again.',
            ], 401);
        }
    }

    // Verify expiration if not demo
    if ($otp !== '123456' && !empty($user['otp_expires_at'])) {
        if (strtotime($user['otp_expires_at']) < time()) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'OTP code has expired. Please request a new code.',
            ], 401);
        }
    }

    // Clear OTP
    $pdo->prepare('UPDATE users SET otp = NULL, otp_expires_at = NULL WHERE id = :id')->execute(['id' => $user['id']]);

    // Issue JWT
    $token = generateJWT([
        'sub' => (string) $user['id'],
        'employee_id' => $user['employee_id'] ?? '',
        'role' => $user['role'] ?? 'member',
    ], $config['jwt_secret'], $config['jwt_expire']);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'OTP verified successfully.',
        'token' => $token,
        'user' => [
            'id' => (int) $user['id'],
            'employee_id' => $user['employee_id'] ?? '',
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'mobile' => $user['mobile'] ?? '',
            'role' => $user['role'] ?? 'member',
            'department' => $user['department'] ?? 'General',
            'designation' => $user['designation'] ?? 'Staff',
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
        ],
    ]);
} catch (PDOException $e) {
    error_log('verify_otp error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to verify OTP: ' . $e->getMessage(),
    ], 500);
}
