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
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$rawBody = file_get_contents('php://input');
$input = [];
if (!empty($rawBody)) {
    try {
        $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Request body must be valid JSON.',
            'data' => null,
        ], 400);
    }
}

$userId = isset($input['user_id']) ? (int) $input['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

$latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
$longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
$address = isset($input['address']) && is_string($input['address']) ? trim($input['address']) : null;

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    // 1. Verify user exists
    $userQuery = $pdo->prepare('SELECT id, full_name, employee_id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $userId]);
    $user = $userQuery->fetch();
    if (!$user) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }

    // 2. Check for an existing open punch in today
    $check = $pdo->prepare('
        SELECT id, punch_in, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today AND punch_out IS NULL
        LIMIT 1
    ');
    $check->execute(['user_id' => $userId, 'today' => $today]);
    $openAttendance = $check->fetch();

    if ($openAttendance) {
        $clockInFormatted = date('h:i A', strtotime($openAttendance['punch_in']));
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You are already punched in for today.',
            'data' => [
                'id' => (int) $openAttendance['id'],
                'user_id' => $userId,
                'date' => $today,
                'punch_in' => $openAttendance['punch_in'],
                'clock_in_time' => $clockInFormatted,
                'is_punched_in' => true,
                'status' => $openAttendance['status'],
            ],
        ], 409);
    }

    // 3. Insert new attendance record
    $insert = $pdo->prepare('
        INSERT INTO attendance (user_id, date, punch_in, status, latitude, longitude, address)
        VALUES (:user_id, :date, :punch_in, :status, :latitude, :longitude, :address)
    ');
    $insert->execute([
        'user_id' => $userId,
        'date' => $today,
        'punch_in' => $now,
        'status' => 'punched_in',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'address' => $address,
    ]);

    $newId = (int) $pdo->lastInsertId();
    $clockInFormatted = date('h:i A', strtotime($now));

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Punched in successfully.',
        'data' => [
            'id' => $newId,
            'user_id' => $userId,
            'date' => $today,
            'punch_in' => $now,
            'clock_in_time' => $clockInFormatted,
            'is_punched_in' => true,
            'status' => 'punched_in',
        ],
    ], 201);
} catch (PDOException $e) {
    error_log('Punch-in failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch in: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
