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
    $query = $pdo->query(
        'SELECT id, name, default_days
         FROM leave_types
         ORDER BY name'
    );
    $types = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Leave type lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave types.',
        'data' => [],
    ], 500);
}

foreach ($types as &$type) {
    $type['default_days'] = (float) $type['default_days'];
}
unset($type);

sendJsonResponse([
    'status' => true,
    'message' => 'Leave types retrieved successfully.',
    'data' => [
        'leave_types' => $types,
    ],
]);
