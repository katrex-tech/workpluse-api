<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = getAuthenticatedUserId();
$rawBody = file_get_contents('php://input');

try {
    $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be valid JSON.',
        'data' => null,
    ], 400);
}

$alertType = $input['alert_type'] ?? 'gps_disabled';
$allowedTypes = ['gps_disabled', 'app_killed', 'mock_location', 'geofence_violation'];
if (!in_array($alertType, $allowedTypes, true)) {
    $alertType = 'gps_disabled';
}

$lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
$lng = isset($input['longitude']) ? (float)$input['longitude'] : null;
$message = trim($input['message'] ?? 'Live location chalu rakhna compulsory hai.');

try {
    $stmt = $pdo->prepare('
        INSERT INTO sales_location_alerts (user_id, alert_type, latitude, longitude, message, is_resolved, created_at)
        VALUES (:uid, :type, :lat, :lng, :msg, 0, NOW())
    ');
    $stmt->execute([
        'uid' => $userId,
        'type' => $alertType,
        'lat' => $lat,
        'lng' => $lng,
        'msg' => $message,
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Location alert recorded.',
        'data' => [
            'id' => (int) $pdo->lastInsertId(),
            'user_id' => $userId,
            'alert_type' => $alertType,
            'alert_message' => 'Live location chalu rakhna compulsory hai.',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ], 201);

} catch (PDOException $e) {
    error_log('location_alert error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to log alert: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
