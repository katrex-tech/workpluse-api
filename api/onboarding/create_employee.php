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
require_once __DIR__ . '/../../config/auth_check.php';

// Strictly protected: ONLY super_admin and hr can onboard employees
$caller = requireRole(['super_admin', 'hr']);
$callerRole = strtolower(trim($caller['role'] ?? ''));

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
$mobile = isset($input['mobile']) && is_string($input['mobile']) ? trim($input['mobile']) : '';
$department = isset($input['department']) && is_string($input['department']) ? trim($input['department']) : '';
$designation = isset($input['designation']) && is_string($input['designation']) ? trim($input['designation']) : '';
$role = isset($input['role']) && is_string($input['role']) ? strtolower(trim($input['role'])) : 'member';
$workLocation = isset($input['work_location']) && is_string($input['work_location']) ? trim($input['work_location']) : 'Tech Tower HQ';
$employmentType = isset($input['employment_type']) && is_string($input['employment_type']) ? trim($input['employment_type']) : 'Full-time';
$dateOfJoining = isset($input['date_of_joining']) && is_string($input['date_of_joining']) ? trim($input['date_of_joining']) : date('Y-m-d');
$customPassword = isset($input['password']) && is_string($input['password']) ? trim($input['password']) : '';
$customEmployeeId = isset($input['employee_id']) && is_string($input['employee_id']) ? trim($input['employee_id']) : '';

// Validation
if ($fullName === '' || $email === '' || $mobile === '' || $department === '' || $designation === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Full Name, Work Email, Mobile, Department, and Designation are required.',
    ], 422);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide a valid corporate email address.',
    ], 422);
}

// Role Hierarchy Enforcement:
// 1. super_admin can create 'hr', 'tl', 'member', 'employee'
// 2. hr can ONLY create 'tl', 'member', 'employee' (NOT super_admin or hr)
$validRoles = ['hr', 'tl', 'member', 'employee'];
if (!in_array($role, $validRoles, true)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => "Invalid role '$role'. Allowed roles: " . implode(', ', $validRoles),
    ], 422);
}

if ($callerRole === 'hr') {
    if ($role === 'super_admin' || $role === 'hr') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Forbidden: HR users cannot create Super Admin or HR roles. Only Super Admin can create HRs.',
        ], 403);
    }
}

try {
    // Check email uniqueness
    $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $checkEmail->execute(['email' => $email]);
    if ($checkEmail->fetch()) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'An employee with this email already exists in the system.',
        ], 409);
    }

    // Determine password
    $tempPassword = null;
    $mustChangePassword = 0;
    if ($customPassword !== '') {
        if (strlen($customPassword) < 6) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Password must be at least 6 characters long.',
            ], 422);
        }
        $finalPassword = $customPassword;
    } else {
        // Auto-generate secure temporary password (e.g. WP@8291)
        $tempPassword = 'WP@' . random_int(1000, 9999);
        $finalPassword = $tempPassword;
        $mustChangePassword = 1;
    }

    $hashedPassword = password_hash($finalPassword, PASSWORD_DEFAULT);

    // Determine Employee ID
    if ($customEmployeeId !== '') {
        $employeeId = $customEmployeeId;
    } else {
        // Generate e.g. WP-2026-XXXX
        $employeeId = 'WP-' . random_int(1000, 9999);
    }

    // Insert user
    $insertStmt = $pdo->prepare('
        INSERT INTO users (
            employee_id,
            full_name,
            email,
            password,
            temp_password,
            mobile,
            department,
            designation,
            role,
            employment_type,
            date_of_joining,
            work_location,
            reporting_manager_id,
            status,
            must_change_password
        ) VALUES (
            :employee_id,
            :full_name,
            :email,
            :password,
            :temp_password,
            :mobile,
            :department,
            :designation,
            :role,
            :employment_type,
            :date_of_joining,
            :work_location,
            :reporting_manager_id,
            :status,
            :must_change_password
        )
    ');

    $insertStmt->execute([
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'email' => $email,
        'password' => $hashedPassword,
        'temp_password' => $tempPassword,
        'mobile' => $mobile,
        'department' => $department,
        'designation' => $designation,
        'role' => $role,
        'employment_type' => $employmentType,
        'date_of_joining' => $dateOfJoining,
        'work_location' => $workLocation,
        'reporting_manager_id' => (int) $caller['id'],
        'status' => 'active',
        'must_change_password' => $mustChangePassword,
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
        'message' => 'Employee onboarded successfully!',
        'data' => [
            'id' => $newUserId,
            'employee_id' => $employeeId,
            'full_name' => $fullName,
            'email' => $email,
            'mobile' => $mobile,
            'department' => $department,
            'designation' => $designation,
            'role' => $role,
            'temp_password' => $tempPassword ?? $finalPassword,
            'must_change_password' => (bool) $mustChangePassword,
            'created_by' => [
                'id' => (int) $caller['id'],
                'name' => $caller['full_name'],
                'role' => $caller['role'],
            ],
        ],
    ], 201);

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'An employee with this email or ID already exists.',
        ], 409);
    }

    error_log('create_employee error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to onboard employee: ' . $e->getMessage(),
    ], 500);
}
