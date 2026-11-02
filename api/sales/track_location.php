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

// Accepts single location or batch of locations
$locations = [];
if (isset($input['locations']) && is_array($input['locations'])) {
    $locations = $input['locations'];
} elseif (isset($input['latitude']) && isset($input['longitude'])) {
    $locations[] = $input;
}

if (empty($locations)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'No location data provided.',
        'data' => null,
    ], 422);
}

try {
    $stmt = $pdo->prepare('
        INSERT INTO sales_tracking_logs (user_id, latitude, longitude, accuracy, speed, battery_level, is_mock, recorded_at, created_at)
        VALUES (:uid, :lat, :lng, :acc, :spd, :bat, :mock, :rec_at, NOW())
    ');

    $insertedCount = 0;
    foreach ($locations as $loc) {
        $lat = isset($loc['latitude']) ? (float)$loc['latitude'] : (isset($loc['lat']) ? (float)$loc['lat'] : null);
        $lng = isset($loc['longitude']) ? (float)$loc['longitude'] : (isset($loc['lng']) ? (float)$loc['lng'] : null);
        if ($lat === null || $lng === null) continue;

        $acc = isset($loc['accuracy']) ? (float)$loc['accuracy'] : null;
        $spd = isset($loc['speed']) ? (float)$loc['speed'] : null;
        $bat = isset($loc['battery_level']) ? (int)$loc['battery_level'] : (isset($loc['battery']) ? (int)$loc['battery'] : null);
        $isMock = !empty($loc['is_mock']) ? 1 : 0;
        $recAt = !empty($loc['recorded_at']) ? $loc['recorded_at'] : date('Y-m-d H:i:s');

        $stmt->execute([
            'uid' => $userId,
            'lat' => $lat,
            'lng' => $lng,
            'acc' => $acc,
            'spd' => $spd,
            'bat' => $bat,
            'mock' => $isMock,
            'rec_at' => $recAt,
        ]);
        $insertedCount++;
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => "Successfully ingested $insertedCount tracking breadcrumbs.",
        'data' => [
            'inserted_count' => $insertedCount,
            'last_recorded_at' => date('Y-m-d H:i:s'),
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('track_location error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to record tracking points: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
