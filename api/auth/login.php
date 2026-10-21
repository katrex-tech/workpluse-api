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

if (!is_array($input)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be a JSON object.',
    ], 400);
}

$email = isset($input['email']) && is_string($input['email'])
    ? strtolower(trim($input['email']))
    : '';
$password = isset($input['password']) && is_string($input['password'])
    ? $input['password']
    : '';

if ($email === '' || $password === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'email and password are required.',
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT id, employee_id, full_name, email, password, role
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $query->execute(['email' => $email]);
    $user = $query->fetch();
} catch (PDOException $exception) {
    error_log('User login lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to log in at this time.',
    ], 500);
}

if ($user === false || !password_verify($password, $user['password'])) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid email or password.',
    ], 401);
}

try {
    $token = generateJWT([
        'sub' => (string) $user['id'],
        'employee_id' => $user['employee_id'],
        'role' => $user['role'],
    ], $config['jwt_secret'], $config['jwt_expire']);
} catch (Throwable $exception) {
    error_log('JWT generation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to log in at this time.',
    ], 500);
}

sendJsonResponse([
    'status' => 'success',
    'message' => 'Login successful.',
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'employee_id' => $user['employee_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ],
]);