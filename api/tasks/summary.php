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

$userIdInput = $_GET['user_id'] ?? null;
$userId = is_string($userIdInput) || is_int($userIdInput)
    ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
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
        "SELECT
            COALESCE(SUM(CASE
                WHEN status IN ('pending', 'in_progress', 'on_hold')
                 AND start_date <= :my_day_start
                 AND (due_date IS NULL OR due_date >= :my_day_end)
                THEN 1 ELSE 0 END), 0) AS my_day,
            COALESCE(SUM(CASE WHEN priority IN ('high', 'urgent', 'important') AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END), 0) AS important,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
            COALESCE(SUM(CASE
                WHEN due_date = :tasks_due_today
                 AND status NOT IN ('completed', 'cancelled')
                THEN 1 ELSE 0 END), 0) AS tasks_due_today,
            COALESCE(SUM(CASE
                WHEN due_date <= :due_or_overdue_through
                 AND status NOT IN ('completed', 'cancelled')
                THEN 1 ELSE 0 END), 0) AS due_overdue
         FROM tasks
         WHERE assigned_to = :user_id"
    );
    $today = date('Y-m-d');
    $query->execute([
        'my_day_start' => $today,
        'my_day_end' => $today,
        'tasks_due_today' => $today,
        'due_or_overdue_through' => $today,
        'user_id' => $userId,
    ]);
    $summary = $query->fetch();
} catch (PDOException $exception) {
    error_log('Task summary lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve task summary.',
        'data' => [],
    ], 500);
}

foreach ($summary as $key => $count) {
    $summary[$key] = (int) $count;
}

sendJsonResponse([
    'status' => true,
    'message' => 'Task summary retrieved successfully.',
    'data' => $summary,
]);
