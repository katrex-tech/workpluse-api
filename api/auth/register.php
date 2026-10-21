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

$fullName = isset($input['full_name']) && is_string($input['full_name'])
    ? trim($input['full_name'])
    : '';
$email = isset($input['email']) && is_string($input['email'])
    ? strtolower(trim($input['email']))
    : '';
$password = isset($input['password']) && is_string($input['password'])
    ? $input['password']
    : '';
$mobile = isset($input['mobile']) && is_string($input['mobile'])
    ? trim($input['mobile'])
    : '';

if ($fullName === '' || $email === '' || $password === '' || $mobile === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'full_name, email, password, and mobile are required.',
    ], 422);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid email address is required.',
    ], 422);
}

if (strlen($fullName) > 255 || strlen($email) > 254 || strlen($mobile) > 32) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'One or more fields exceed the allowed length.',
    ], 422);
}

try {
    $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $emailCheck->execute(['email' => $email]);

    if ($emailCheck->fetch() !== false) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'An account with this email already exists.',
        ], 409);
    }

    $employeeId = 'EMP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    if ($hashedPassword === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (employee_id, full_name, email, password, mobile, role)
         VALUES (:employee_id, :full_name, :email, :password, :mobile, :role)'
    );
    $insert->execute([
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'email' => $email,
        'password' => $hashedPassword,
        'mobile' => $mobile,
        'role' => 'employee',
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'An account with this email already exists.',
        ], 409);
    }

    error_log('User registration failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to register the account.',
    ], 500);
} catch (Throwable $exception) {
    error_log('User registration failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to register the account.',
    ], 500);
}

sendJsonResponse([
    'status' => 'success',
    'message' => 'Registration successful.',
], 201);