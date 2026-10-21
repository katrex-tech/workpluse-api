<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($userId === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid user_id query parameter is required.',
        'data' => null,
    ], 422);
}

$attendanceDate = date('Y-m-d');

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1');
    $userQuery->execute(['user_id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }

    $query = $pdo->prepare(
        'SELECT punch_in, punch_out, working_hours
         FROM attendance
         WHERE user_id = :user_id AND attendance_date = :attendance_date
         LIMIT 1'
    );
    $query->execute([
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
    ]);
    $attendance = $query->fetch();
} catch (PDOException $exception) {
    error_log('Today attendance lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to retrieve today\'s attendance.',
        'data' => null,
    ], 500);
}

sendJsonResponse([
    'status' => 'success',
    'message' => 'Today\'s attendance retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
        'is_punched_in' => $attendance !== false && $attendance['punch_out'] === null,
        'punch_in' => $attendance === false ? null : $attendance['punch_in'],
        'punch_out' => $attendance === false ? null : $attendance['punch_out'],
        'working_hours' => $attendance === false || $attendance['working_hours'] === null
            ? null
            : (float) $attendance['working_hours'],
    ],
]);
