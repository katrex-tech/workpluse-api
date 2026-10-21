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

$rawBody = file_get_contents('php://input');
try {
    $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be valid JSON.',
        'data' => null,
    ], 400);
}

if (!is_array($input) || !isset($input['user_id'])) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'user_id is required.',
        'data' => null,
    ], 422);
}

$userId = filter_var($input['user_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($userId === false) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'user_id must be a positive integer.',
        'data' => null,
    ], 422);
}

$attendanceDate = date('Y-m-d');
$punchInTime = date('H:i:s');

try {
    $pdo->beginTransaction();

    $userQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = :user_id LIMIT 1 FOR UPDATE'
    );
    $userQuery->execute(['user_id' => $userId]);
    if ($userQuery->fetch() === false) {
        $pdo->rollBack();
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }

    $existingQuery = $pdo->prepare(
        'SELECT id FROM attendance
         WHERE user_id = :user_id AND attendance_date = :attendance_date
         LIMIT 1'
    );
    $existingQuery->execute([
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
    ]);
    if ($existingQuery->fetch() !== false) {
        $pdo->rollBack();
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You have already punched in today.',
            'data' => null,
        ], 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO attendance (user_id, attendance_date, punch_in)
         VALUES (:user_id, :attendance_date, :punch_in)'
    );
    $insert->execute([
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
        'punch_in' => $punchInTime,
    ]);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception->getCode() === '23000') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You have already punched in today.',
            'data' => null,
        ], 409);
    }

    error_log('Attendance punch-in failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch-in.',
        'data' => null,
    ], 500);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Attendance punch-in failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch-in.',
        'data' => null,
    ], 500);
}

sendJsonResponse([
    'status' => 'success',
    'message' => 'Punch-in recorded successfully.',
    'data' => [
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
        'punch_in_time' => $punchInTime,
    ],
], 201);
