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
$status = $_GET['status'] ?? null;
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];

if ($userId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid user_id query parameter is required.',
        'data' => [],
    ], 422);
}

if ($status !== null && (!is_string($status) || !in_array(strtolower($status), $allowedStatuses, true))) {
    sendJsonResponse([
        'status' => false,
        'message' => 'status must be pending, approved, rejected, or cancelled.',
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

    $sql = 'SELECT
                l.id,
                l.user_id,
                l.leave_type,
                l.from_date,
                l.to_date,
                l.half_day,
                l.reason,
                l.status,
                l.applied_at
            FROM leaves AS l
            WHERE l.user_id = :user_id';
    $parameters = ['user_id' => $userId];

    if ($status !== null && strtolower($status) !== 'all') {
        $sql .= ' AND LOWER(l.status) = :status';
        $parameters['status'] = strtolower($status);
    }
    $sql .= ' ORDER BY l.applied_at DESC, l.id DESC';

    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $applications = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Leave application list failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave applications.',
        'data' => [],
    ], 500);
}

foreach ($applications as &$app) {
    $from = new DateTime($app['from_date']);
    $to = new DateTime($app['to_date']);
    $days = $from->diff($to)->days + 1;
    if ($app['half_day'] !== 'none') {
        $days = 0.5;
    }
    $app['duration_days'] = $days;
    $app['duration_formatted'] = $days == 1 ? '1 Day' : ($days == 0.5 ? 'Half Day' : "$days Days");
    $app['formatted_dates'] = date('d M Y', strtotime($app['from_date'])) .
        ($app['from_date'] !== $app['to_date'] ? ' - ' . date('d M Y', strtotime($app['to_date'])) : '');
    $app['applied_on'] = date('d M Y', strtotime($app['applied_at']));
}
unset($app);

sendJsonResponse([
    'status' => true,
    'message' => 'Leave applications retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'applications' => $applications,
    ],
]);
