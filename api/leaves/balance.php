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

$userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($userId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid user_id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1');
    $userQuery->execute(['user_id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $query = $pdo->prepare(
        'SELECT
            lt.id AS leave_type_id,
            lt.name AS leave_type,
            COALESCE(lb.total_days, lt.default_days, 0) AS total_days,
            COALESCE(lb.used_days, 0) AS used_days
         FROM leave_types AS lt
         LEFT JOIN leave_balances AS lb
           ON lb.leave_type_id = lt.id AND lb.user_id = :user_id
         ORDER BY lt.name'
    );
    $query->execute(['user_id' => $userId]);
    $balances = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Leave balance lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave balance.',
        'data' => [],
    ], 500);
}

foreach ($balances as &$balance) {
    $balance['total_days'] = (float) $balance['total_days'];
    $balance['used_days'] = (float) $balance['used_days'];
    $balance['remaining_days'] = $balance['total_days'] - $balance['used_days'];
}
unset($balance);

sendJsonResponse([
    'status' => true,
    'message' => 'Leave balance retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'balances' => $balances,
    ],
]);
