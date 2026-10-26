<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $department = $_GET['department'] ?? null;
    $sql = "SELECT
                id,
                employee_id,
                full_name,
                email,
                mobile,
                designation,
                department,
                profile_photo,
                role,
                employment_type,
                date_of_joining,
                work_location,
                status
             FROM users
             WHERE status = :status";
    $params = ['status' => 'active'];

    if ($department !== null && trim($department) !== '') {
        $sql .= " AND department = :department";
        $params['department'] = trim($department);
    }

    $sql .= " ORDER BY full_name ASC";

    $query = $pdo->prepare($sql);
    $query->execute($params);
    $teamMembers = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Team member list failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve team members.',
        'data' => [],
    ], 500);
}

// Compute initials and fallback designation
foreach ($teamMembers as &$member) {
    $nameParts = explode(' ', trim($member['full_name']));
    $initials = '';
    if (isset($nameParts[0][0])) $initials .= strtoupper($nameParts[0][0]);
    if (isset($nameParts[1][0])) $initials .= strtoupper($nameParts[1][0]);
    $member['initials'] = $initials ?: 'WP';
    if (empty($member['designation'])) {
        $member['designation'] = 'Team Member';
    }
    if (empty($member['department'])) {
        $member['department'] = 'Engineering';
    }
    $member['profile_photo'] = formatMediaUrl($member['profile_photo'] ?? null);
}
unset($member);

sendJsonResponse([
    'status' => true,
    'message' => 'Team members retrieved successfully.',
    'data' => $teamMembers,
]);
