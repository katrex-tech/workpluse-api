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

$year = (int) ($_GET['year'] ?? date('Y'));

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
            id,
            leave_type,
            total,
            used,
            remaining,
            year
         FROM leave_balance
         WHERE user_id = :user_id AND year = :year
         ORDER BY id ASC'
    );
    $query->execute([
        'user_id' => $userId,
        'year' => $year,
    ]);
    $balances = $query->fetchAll();

    // If user has no balance records seeded yet for this year, seed default allocations
    if (empty($balances)) {
        $defaultTypes = [
            ['type' => 'Casual Leave', 'total' => 12.0],
            ['type' => 'Sick Leave', 'total' => 10.0],
            ['type' => 'Annual Leave', 'total' => 15.0],
        ];

        $insert = $pdo->prepare(
            'INSERT INTO leave_balance (user_id, leave_type, total, used, remaining, year)
             VALUES (:user_id, :leave_type, :total, 0.0, :remaining, :year)'
        );

        foreach ($defaultTypes as $dt) {
            $insert->execute([
                'user_id' => $userId,
                'leave_type' => $dt['type'],
                'total' => $dt['total'],
                'remaining' => $dt['total'],
                'year' => $year,
            ]);
        }

        $query->execute([
            'user_id' => $userId,
            'year' => $year,
        ]);
        $balances = $query->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('Leave balance lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave balance.',
        'data' => [],
    ], 500);
}

$totalRemaining = 0.0;
foreach ($balances as &$balance) {
    $balance['total'] = (float) $balance['total'];
    $balance['used'] = (float) $balance['used'];
    $balance['remaining'] = (float) $balance['remaining'];
    // provide aliases for frontend compatibility
    $balance['total_days'] = $balance['total'];
    $balance['used_days'] = $balance['used'];
    $balance['remaining_days'] = $balance['remaining'];
    $totalRemaining += $balance['remaining'];
}
unset($balance);

sendJsonResponse([
    'status' => true,
    'message' => 'Leave balance retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'year' => $year,
        'total_remaining_days' => $totalRemaining,
        'balances' => $balances,
    ],
]);
