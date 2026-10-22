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
    // Try to get distinct leave types configured in leave_balance or default set
    $types = [
        ['id' => 1, 'name' => 'Casual Leave', 'default_days' => 12.0],
        ['id' => 2, 'name' => 'Sick Leave', 'default_days' => 10.0],
        ['id' => 3, 'name' => 'Annual Leave', 'default_days' => 15.0],
        ['id' => 4, 'name' => 'Privilege Leave', 'default_days' => 8.0],
    ];

    sendJsonResponse([
        'status' => true,
        'message' => 'Leave types retrieved successfully.',
        'data' => [
            'leave_types' => $types,
        ],
    ]);
} catch (Exception $exception) {
    error_log('Leave type lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave types.',
        'data' => [],
    ], 500);
}
