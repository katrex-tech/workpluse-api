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
$userId = null;
if ($userIdInput !== null) {
    $userId = is_string($userIdInput) || is_int($userIdInput)
        ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($userId === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'user_id must be a positive integer.',
            'data' => [],
        ], 422);
    }
}

$sql = 'SELECT
            wr.id,
            wr.user_id,
            u.full_name AS user_name,
            wr.report_date,
            wr.description,
            wr.status,
            wr.created_at
        FROM work_reports AS wr
        INNER JOIN users AS u ON u.id = wr.user_id';
$parameters = [];
if ($userId !== null) {
    $sql .= ' WHERE wr.user_id = :user_id';
    $parameters['user_id'] = $userId;
}
$sql .= ' ORDER BY wr.report_date DESC, wr.id DESC';

try {
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $reports = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Work report listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve work reports.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Work reports retrieved successfully.',
    'data' => $reports,
]);
