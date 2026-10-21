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
$punchOutTime = date('H:i:s');

try {
    $pdo->beginTransaction();

    $select = $pdo->prepare(
        'SELECT id, punch_in FROM attendance
         WHERE user_id = :user_id
           AND attendance_date = :attendance_date
           AND punch_out IS NULL
         LIMIT 1
         FOR UPDATE'
    );
    $select->execute([
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
    ]);
    $attendance = $select->fetch();

    if ($attendance === false) {
        $pdo->rollBack();
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No open punch-in was found for today.',
            'data' => null,
        ], 404);
    }

    $start = new DateTimeImmutable($attendanceDate . ' ' . $attendance['punch_in']);
    $end = new DateTimeImmutable($attendanceDate . ' ' . $punchOutTime);
    $elapsedSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
    $workingHours = round($elapsedSeconds / 3600, 2);

    $update = $pdo->prepare(
        'UPDATE attendance
         SET punch_out = :punch_out, working_hours = :working_hours
         WHERE id = :id AND punch_out IS NULL'
    );
    $update->execute([
        'punch_out' => $punchOutTime,
        'working_hours' => $workingHours,
        'id' => $attendance['id'],
    ]);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Attendance punch-out failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch-out.',
        'data' => null,
    ], 500);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Attendance punch-out failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch-out.',
        'data' => null,
    ], 500);
}

sendJsonResponse([
    'status' => 'success',
    'message' => 'Punch-out recorded successfully.',
    'data' => [
        'user_id' => $userId,
        'attendance_date' => $attendanceDate,
        'punch_out_time' => $punchOutTime,
        'working_hours' => $workingHours,
    ],
]);
