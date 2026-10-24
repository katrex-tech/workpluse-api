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
$shiftId = filter_var($input['shift_id'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$workLocation = isset($input['work_location']) && is_string($input['work_location']) ? trim($input['work_location']) : 'Tech Tower HQ';
$employmentType = isset($input['employment_type']) && is_string($input['employment_type']) ? trim($input['employment_type']) : 'Full-time';
$dateOfJoining = isset($input['date_of_joining']) && is_string($input['date_of_joining']) ? trim($input['date_of_joining']) : date('Y-m-d');
$rawDob = isset($input['dob']) && is_string($input['dob']) ? trim($input['dob']) : '';
$customPassword = isset($input['password']) && is_string($input['password']) ? trim($input['password']) : '';
$customEmployeeId = isset($input['employee_id']) && is_string($input['employee_id']) ? trim($input['employee_id']) : '';

// Company Location Coordinates for Geofencing
$companyLatitude = isset($input['company_latitude']) ? (float)$input['company_latitude'] : (isset($input['registered_lat']) ? (float)$input['registered_lat'] : null);
$companyLongitude = isset($input['company_longitude']) ? (float)$input['company_longitude'] : (isset($input['registered_lng']) ? (float)$input['registered_lng'] : null);
$companyAddress = isset($input['company_address']) && is_string($input['company_address']) ? trim($input['company_address']) : (isset($input['registered_address']) ? trim($input['registered_address']) : $workLocation);
$companyRadius = isset($input['radius_meters']) ? (int)$input['radius_meters'] : 200;

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
    $dobFormatted = $rawDob;
    $dobDigits = preg_replace('/\D/', '', $rawDob);
    if (strlen($dobDigits) === 8) {
        if ((int)substr($dobDigits, 0, 4) > 1900 && (int)substr($dobDigits, 4, 2) <= 12) {
            $yyyy = substr($dobDigits, 0, 4);
            $mm = substr($dobDigits, 4, 2);
            $dd = substr($dobDigits, 6, 2);
            $dobFormatted = "$dd$mm$yyyy";
        } else {
            $dobFormatted = $dobDigits;
        }
    }

    if ($customPassword !== '') {
        if (strlen($customPassword) < 6) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Password must be at least 6 characters long.',
            ], 422);
        }
        $finalPassword = $customPassword;
    } elseif ($dobFormatted !== '') {
        // User requested DOB in ddmmyyyy format as default initial password
        $finalPassword = $dobFormatted;
        $tempPassword = $dobFormatted;
        $mustChangePassword = 0;
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
            shift_id,
            employment_type,
            date_of_joining,
            dob,
            work_location,
            registered_lat,
            registered_lng,
            registered_address,
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
            :shift_id,
            :employment_type,
            :date_of_joining,
            :dob,
            :work_location,
            :registered_lat,
            :registered_lng,
            :registered_address,
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
        'shift_id' => $shiftId,
        'employment_type' => $employmentType,
        'date_of_joining' => $dateOfJoining,
        'dob' => $dobFormatted,
        'work_location' => $workLocation,
        'registered_lat' => $companyLatitude,
        'registered_lng' => $companyLongitude,
        'registered_address' => $companyAddress,
        'reporting_manager_id' => (int) $caller['id'],
        'status' => 'active',
        'must_change_password' => $mustChangePassword,
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    // If company location coordinates provided, update company_locations table
    if ($companyLatitude !== null && $companyLongitude !== null) {
        try {
            $compUpdate = $pdo->prepare('
                UPDATE company_locations 
                SET latitude = :lat, longitude = :lng, address = :addr, radius_meters = :radius, is_active = 1
                WHERE id = 1
            ');
            $compUpdate->execute([
                'lat' => $companyLatitude,
                'lng' => $companyLongitude,
                'addr' => $companyAddress,
                'radius' => $companyRadius,
            ]);
        } catch (_) {}
    }

    // Fetch assigned shift details for response
    $shiftDetails = $pdo->query("SELECT name, start_time, end_time FROM shifts WHERE id = $shiftId")->fetch(PDO::FETCH_ASSOC);
    $shiftName = $shiftDetails ? $shiftDetails['name'] : 'Regular Shift (10 AM - 7 PM)';

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
            'shift_id' => $shiftId,
            'shift_name' => $shiftName,
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
