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

// Support both 'email' or 'mobile' or generic 'identifier'
$identifier = isset($input['identifier']) && is_string($input['identifier'])
    ? trim($input['identifier'])
    : (isset($input['email']) && is_string($input['email'])
        ? trim($input['email'])
        : (isset($input['mobile']) && is_string($input['mobile'])
            ? trim($input['mobile'])
            : ''));

$password = isset($input['password']) && is_string($input['password'])
    ? $input['password']
    : '';

if ($identifier === '' || $password === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Email/Mobile and password are required.',
    ], 422);
}

$cleanEmail = strtolower($identifier);

try {
    $query = $pdo->prepare(
        'SELECT id, employee_id, full_name, email, password, mobile, role, department, designation, status, must_change_password,
                is_field_sales, daily_meeting_target, base_lat, base_lng, base_address, registered_lat, registered_lng, registered_address
         FROM users
         WHERE LOWER(email) = :email OR mobile = :mobile
         LIMIT 1'
    );
    $query->execute([
        'email' => $cleanEmail,
        'mobile' => $identifier,
    ]);
    $user = $query->fetch();
} catch (PDOException $exception) {
    error_log('User login lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to log in at this time.',
    ], 500);
}

$isPasswordValid = false;
if ($user !== false) {
    if ($user['password'] === $password) {
        $isPasswordValid = true;
    } elseif (password_verify($password, $user['password'])) {
        $isPasswordValid = true;
    }
}

if (!$isPasswordValid) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid email or password.',
    ], 401);
}

if (isset($user['status']) && $user['status'] === 'inactive') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Your account has been deactivated. Please contact your HR or Super Admin.',
    ], 403);
}

try {
    $token = generateJWT([
        'sub' => (string) $user['id'],
        'employee_id' => $user['employee_id'] ?? '',
        'role' => $user['role'] ?? 'member',
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
        'id' => (int) $user['id'],
        'employee_id' => $user['employee_id'] ?? '',
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'mobile' => $user['mobile'] ?? '',
        'role' => $user['role'] ?? 'member',
        'department' => $user['department'] ?? 'General',
        'designation' => $user['designation'] ?? 'Staff',
        'is_field_sales' => (bool) ($user['is_field_sales'] ?? false),
        'daily_meeting_target' => (int) ($user['daily_meeting_target'] ?? 5),
        'base_lat' => !empty($user['base_lat']) ? (float)$user['base_lat'] : (!empty($user['registered_lat']) ? (float)$user['registered_lat'] : null),
        'base_lng' => !empty($user['base_lng']) ? (float)$user['base_lng'] : (!empty($user['registered_lng']) ? (float)$user['registered_lng'] : null),
        'base_address' => $user['base_address'] ?? ($user['registered_address'] ?? null),
        'profile_photo' => formatMediaUrl($user['profile_photo'] ?? null),
        'must_change_password' => (bool) ($user['must_change_password'] ?? false),
    ],
]);
