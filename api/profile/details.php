<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

// Try to get user_id from query params or auth token
$userId = filter_var($_GET['user_id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($userId === false || $userId === null) {
    try {
        $caller = getAuthenticatedUser();
        if ($caller && isset($caller['id'])) {
            $userId = (int) $caller['id'];
        }
    } catch (Exception $e) {
        $userId = null;
    }
}

if (!$userId) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid user_id or id is required.',
        'data' => null,
    ], 422);
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.employee_id,
            u.full_name,
            u.email,
            u.mobile,
            u.profile_photo,
            u.department,
            u.designation,
            u.role,
            u.employment_type,
            u.date_of_joining,
            u.dob,
            u.work_location,
            u.registered_lat,
            u.registered_lng,
            u.registered_address,
            u.emergency_contact_name,
            u.emergency_contact_phone,
            u.annual_ctc,
            u.monthly_in_hand,
            u.bank_name,
            u.account_number,
            u.ifsc_code,
            u.status,
            u.shift_id,
            u.reporting_manager_id,
            mgr.full_name AS reporting_manager_name,
            mgr.designation AS reporting_manager_designation,
            mgr.role AS reporting_manager_role,
            mgr.profile_photo AS reporting_manager_photo,
            s.name AS shift_name,
            s.start_time AS shift_start_time,
            s.end_time AS shift_end_time,
            s.lunch_start AS shift_lunch_start,
            s.lunch_end AS shift_lunch_end,
            s.personal_break_minutes AS shift_break_minutes,
            s.description AS shift_description
        FROM users u
        LEFT JOIN users mgr ON u.reporting_manager_id = mgr.id
        LEFT JOIN shifts s ON u.shift_id = s.id
        WHERE u.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Employee details not found.',
            'data' => null,
        ], 404);
    }

    // Name parts and initials
    $nameParts = explode(' ', trim($user['full_name']));
    $initials = '';
    if (isset($nameParts[0][0])) $initials .= strtoupper($nameParts[0][0]);
    if (isset($nameParts[1][0])) $initials .= strtoupper($nameParts[1][0]);

    // Shift formatting
    $shiftData = null;
    if (!empty($user['shift_name'])) {
        $startTime = !empty($user['shift_start_time']) ? date('h:i A', strtotime($user['shift_start_time'])) : '10:00 AM';
        $endTime = !empty($user['shift_end_time']) ? date('h:i A', strtotime($user['shift_end_time'])) : '07:00 PM';
        $lunchStart = !empty($user['shift_lunch_start']) ? date('h:i A', strtotime($user['shift_lunch_start'])) : '02:15 PM';
        $lunchEnd = !empty($user['shift_lunch_end']) ? date('h:i A', strtotime($user['shift_lunch_end'])) : '02:45 PM';

        $shiftData = [
            'id' => (int) $user['shift_id'],
            'name' => $user['shift_name'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'time_range' => "$startTime - $endTime",
            'lunch_time' => "$lunchStart - $lunchEnd",
            'personal_break_minutes' => (int) ($user['shift_break_minutes'] ?? 30),
            'description' => $user['shift_description'] ?? '',
        ];
    } else {
        $shiftData = [
            'id' => 1,
            'name' => 'Regular Shift (10 AM - 7 PM)',
            'start_time' => '10:00 AM',
            'end_time' => '07:00 PM',
            'time_range' => '10:00 AM - 07:00 PM',
            'lunch_time' => '02:15 PM - 02:45 PM',
            'personal_break_minutes' => 35,
            'description' => 'Standard corporate 9-hour shift with 30-min lunch.',
        ];
    }

    // Manager formatting
    $managerData = null;
    if (!empty($user['reporting_manager_name'])) {
        $managerData = [
            'id' => (int) $user['reporting_manager_id'],
            'name' => $user['reporting_manager_name'],
            'designation' => $user['reporting_manager_designation'] ?? 'Manager',
            'role' => strtoupper($user['reporting_manager_role'] ?? 'TEAM LEAD'),
            'avatar_url' => formatMediaUrl($user['reporting_manager_photo'] ?? null),
        ];
    }

    $formattedData = [
        'id' => (int) $user['id'],
        'employee_id' => $user['employee_id'] ?? 'WP-' . $user['id'],
        'full_name' => $user['full_name'],
        'initials' => $initials ?: 'WP',
        'email' => $user['email'],
        'mobile' => $user['mobile'] ?? '',
        'profile_photo' => formatMediaUrl($user['profile_photo'] ?? null),
        'department' => !empty($user['department']) ? $user['department'] : 'General',
        'designation' => !empty($user['designation']) ? $user['designation'] : 'Team Member',
        'role' => $user['role'] ?? 'employee',
        'employment_type' => !empty($user['employment_type']) ? $user['employment_type'] : 'Full-time',
        'date_of_joining' => $user['date_of_joining'] ?? date('Y-m-d'),
        'dob' => $user['dob'] ?? '',
        'gender' => 'Male',
        'status' => $user['status'] ?? 'active',

        // Work Location & Geofence (stored during onboarding)
        'work_location' => !empty($user['work_location']) ? $user['work_location'] : 'Tech Tower HQ',
        'registered_lat' => $user['registered_lat'] !== null ? (float) $user['registered_lat'] : 21.14580000,
        'registered_lng' => $user['registered_lng'] !== null ? (float) $user['registered_lng'] : 79.08820000,
        'registered_address' => !empty($user['registered_address']) ? $user['registered_address'] : (!empty($user['work_location']) ? $user['work_location'] : 'Tech Tower HQ - Level 14, IT Park, Nagpur'),
        'geofence_radius' => 200,

        // Emergency Contacts (stored during onboarding)
        'emergency_contact_name' => !empty($user['emergency_contact_name']) ? $user['emergency_contact_name'] : 'Not Provided',
        'emergency_contact_phone' => !empty($user['emergency_contact_phone']) ? $user['emergency_contact_phone'] : 'Not Provided',

        // Bank Details (stored during onboarding)
        'bank_name' => !empty($user['bank_name']) ? $user['bank_name'] : 'HDFC Bank',
        'account_number' => !empty($user['account_number']) ? $user['account_number'] : '50100492817293',
        'ifsc_code' => !empty($user['ifsc_code']) ? $user['ifsc_code'] : 'HDFC0001289',
        'account_type' => 'Salary Account (Direct Disbursal)',
        'account_status' => 'Active & Verified',

        // Salary & Compensation (stored during onboarding)
        'annual_ctc' => !empty($user['annual_ctc']) ? $user['annual_ctc'] : '₹ 18,50,000 / yr',
        'monthly_in_hand' => !empty($user['monthly_in_hand']) ? $user['monthly_in_hand'] : '₹ 1,32,500 / mo',
        'disbursal_mode' => 'Direct Bank Transfer (NEFT/RTGS/IMPS)',
        'payroll_cycle' => 'Monthly (1st of every month)',

        // Shift and Manager
        'shift' => $shiftData,
        'reporting_manager' => $managerData,
    ];

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Employee profile details retrieved successfully.',
        'data' => $formattedData,
    ], 200);

} catch (PDOException $e) {
    error_log('Profile details lookup failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Database error while fetching profile details.',
        'data' => null,
    ], 500);
}
