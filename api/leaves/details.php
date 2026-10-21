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
            la.id,
            la.user_id,
            la.leave_type_id,
            lt.name AS leave_type,
            la.from_date,
            la.to_date,
            la.half_day,
            la.reason,
            la.status,
            la.applied_at
         FROM leave_applications AS la
         INNER JOIN leave_types AS lt ON lt.id = la.leave_type_id
         WHERE la.id = :id
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

$application['half_day'] = (bool) $application['half_day'];

sendJsonResponse([
    'status' => true,
    'message' => 'Leave application retrieved successfully.',
    'data' => $application,
]);
