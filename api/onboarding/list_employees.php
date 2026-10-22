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
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

// Allowed for super_admin and hr
$caller = requireRole(['super_admin', 'hr']);

try {
    $stmt = $pdo->prepare('
        SELECT id, employee_id, full_name, email, mobile, department, designation, role, employment_type, date_of_joining, work_location, status, created_at
        FROM users
        ORDER BY id DESC
    ');
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($employees as &$emp) {
        $emp['id'] = (int) $emp['id'];
    }
    unset($emp);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Onboarded employees retrieved successfully.',
        'count' => count($employees),
        'data' => $employees,
    ]);
} catch (PDOException $e) {
    error_log('list_employees error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load employees: ' . $e->getMessage(),
        'data' => [],
    ], 500);
}
