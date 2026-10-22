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

if (!is_array($input)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be a JSON object.',
    ], 400);
}

$email = isset($input['email']) && is_string($input['email']) ? strtolower(trim($input['email'])) : '';
$password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
$confirmPassword = isset($input['confirm_password']) && is_string($input['confirm_password']) ? $input['confirm_password'] : '';

if ($email === '' || $password === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Email and new password are required.',
    ], 422);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid work email address is required.',
    ], 422);
}

if (strlen($password) < 6) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Password must be at least 6 characters long.',
    ], 422);
}

if ($confirmPassword !== '' && $password !== $confirmPassword) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Password and confirmation password do not match.',
    ], 422);
}

try {
    // Check if user exists with this email
    $stmt = $pdo->prepare('SELECT id, full_name, email, status FROM users WHERE LOWER(email) = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No account registered with this email address.',
        ], 404);
    }

    if (isset($user['status']) && $user['status'] === 'suspended') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'This account has been deactivated. Please contact your administrator.',
        ], 403);
    }

    // Hash the new password and update
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $updateStmt = $pdo->prepare('UPDATE users SET password = :password, must_change_password = 0 WHERE id = :id');
    $updateStmt->execute([
        'password' => $hashedPassword,
        'id' => (int) $user['id'],
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Password reset successfully! You can now sign in with your new password.',
        'data' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
        ],
    ], 200);

} catch (PDOException $exception) {
    error_log('Password reset failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'An internal database error occurred while resetting password.',
    ], 500);
}
