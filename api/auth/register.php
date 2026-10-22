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

$fullName = isset($input['full_name']) && is_string($input['full_name']) ? trim($input['full_name']) : '';
$email = isset($input['email']) && is_string($input['email']) ? strtolower(trim($input['email'])) : '';
$password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
$mobile = isset($input['mobile']) && is_string($input['mobile']) ? trim($input['mobile']) : '';
// Default role is always 'employee' on public registration
$role = 'employee';

if ($fullName === '' || $email === '' || $password === '' || $mobile === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Full Name, Email, Password, and Mobile are required.',
    ], 422);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid corporate or work email address is required.',
    ], 422);
}

if (strlen($password) < 6) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Password must be at least 6 characters long.',
    ], 422);
}

try {
    $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $emailCheck->execute(['email' => $email]);

    if ($emailCheck->fetch() !== false) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'An account with this email already exists. Please log in.',
        ], 409);
    }

    $employeeId = 'WP-' . date('ym') . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        'INSERT INTO users (employee_id, full_name, email, password, mobile, role, department, designation, status)
         VALUES (:employee_id, :full_name, :email, :password, :mobile, :role, :department, :designation, :status)'
    );
    $insert->execute([
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'email' => $email,
        'password' => $hashedPassword,
        'mobile' => $mobile,
        'role' => $role,
        'department' => $input['department'] ?? 'General',
        'designation' => $input['designation'] ?? 'Employee',
        'status' => 'active',
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    // Initialize default leave balance for new employee
    try {
        $year = (int) date('Y');
        $pdo->prepare('
            INSERT INTO leave_balance (user_id, leave_type, total, used, remaining, year)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$newUserId, 'Casual Leave', 12.0, 0.0, 12.0, $year]);

        $pdo->prepare('
            INSERT INTO leave_balance (user_id, leave_type, total, used, remaining, year)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$newUserId, 'Sick Leave', 6.0, 0.0, 6.0, $year]);
    } catch (_) {}

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Registration successful! You can now log in with your credentials.',
        'data' => [
            'id' => $newUserId,
            'employee_id' => $employeeId,
            'full_name' => $fullName,
            'email' => $email,
            'role' => $role,
        ],
    ], 201);
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
        'message' => 'Unable to register the account: ' . $exception->getMessage(),
    ], 500);
}
