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

if ($status !== null && (!is_string($status) || !in_array($status, $allowedStatuses, true))) {
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
                la.id,
                lt.name AS leave_type,
                la.from_date,
                la.to_date,
                la.half_day,
                la.reason,
                la.status,
                la.applied_at
            FROM leave_applications AS la
            INNER JOIN leave_types AS lt ON lt.id = la.leave_type_id
            WHERE la.user_id = :user_id';
    $parameters = ['user_id' => $userId];

    if ($status !== null) {
        $sql .= ' AND la.status = :status';
        $parameters['status'] = $status;
    }
    $sql .= ' ORDER BY la.applied_at DESC, la.id DESC';

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

foreach ($applications as &$application) {
    $application['half_day'] = (bool) $application['half_day'];
}
unset($application);

sendJsonResponse([
    'status' => true,
    'message' => 'Leave applications retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'applications' => $applications,
    ],
]);
