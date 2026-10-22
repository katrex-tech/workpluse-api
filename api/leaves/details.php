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

$applicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($applicationId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT
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
         WHERE l.id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $applicationId]);
    $application = $query->fetch();
} catch (PDOException $exception) {
    error_log('Leave application lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve leave application details.',
        'data' => [],
    ], 500);
}

if ($application === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Leave application not found.',
        'data' => [],
    ], 404);
}

$from = new DateTime($application['from_date']);
$to = new DateTime($application['to_date']);
$days = $from->diff($to)->days + 1;
if ($application['half_day'] !== 'none') {
    $days = 0.5;
}
$application['duration_days'] = $days;
$application['duration_formatted'] = $days == 1 ? '1 Day' : ($days == 0.5 ? 'Half Day' : "$days Days");
$application['formatted_dates'] = date('d M Y', strtotime($application['from_date'])) .
    ($application['from_date'] !== $application['to_date'] ? ' - ' . date('d M Y', strtotime($application['to_date'])) : '');
$application['applied_on'] = date('d M Y', strtotime($application['applied_at']));

sendJsonResponse([
    'status' => true,
    'message' => 'Leave application retrieved successfully.',
    'data' => $application,
]);
