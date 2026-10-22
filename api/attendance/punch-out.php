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

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    // Find open attendance record
    $select = $pdo->prepare('
        SELECT id, punch_in, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today AND punch_out IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $select->execute(['user_id' => $userId, 'today' => $today]);
    $openAttendance = $select->fetch();

    if (!$openAttendance) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No active punch-in was found for today.',
            'data' => null,
        ], 404);
    }

    $inTimestamp = strtotime($openAttendance['punch_in']);
    $outTimestamp = strtotime($now);
    $diffSeconds = max(0, $outTimestamp - $inTimestamp);
    $workingHours = round($diffSeconds / 3600, 2);

    $hours = floor($diffSeconds / 3600);
    $minutes = floor(($diffSeconds % 3600) / 60);
    $hoursLogged = "{$hours}h {$minutes}m";

    $update = $pdo->prepare('
        UPDATE attendance
        SET punch_out = :punch_out, working_hours = :working_hours, status = :status
        WHERE id = :id
    ');
    $update->execute([
        'punch_out' => $now,
        'working_hours' => $workingHours,
        'status' => 'completed',
        'id' => $openAttendance['id'],
    ]);

    $clockInFormatted = date('h:i A', $inTimestamp);
    $clockOutFormatted = date('h:i A', $outTimestamp);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Punched out successfully.',
        'data' => [
            'id' => (int) $openAttendance['id'],
            'user_id' => $userId,
            'date' => $today,
            'punch_in' => $openAttendance['punch_in'],
            'punch_out' => $now,
            'clock_in_time' => $clockInFormatted,
            'clock_out_time' => $clockOutFormatted,
            'working_hours' => $workingHours,
            'hours_logged' => $hoursLogged,
            'is_punched_in' => false,
            'status' => 'completed',
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Punch-out failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch out: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
