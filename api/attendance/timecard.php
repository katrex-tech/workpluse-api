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

try {
    $userQuery = $pdo->prepare(
        'SELECT id, employee_id, full_name, email, role
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );
    $userQuery->execute(['user_id' => $userId]);
    $user = $userQuery->fetch();

    if ($user === false) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }

    $attendanceQuery = $pdo->prepare(
        'SELECT id, attendance_date, punch_in, punch_out, working_hours
         FROM attendance
         WHERE user_id = :user_id
         ORDER BY attendance_date DESC'
    );
    $attendanceQuery->execute(['user_id' => $userId]);
    $records = $attendanceQuery->fetchAll();
} catch (PDOException $exception) {
    error_log('Timecard lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to retrieve the timecard.',
        'data' => null,
    ], 500);
}

$totalWorkingHours = 0.0;
$completedDays = 0;
foreach ($records as &$record) {
    if ($record['working_hours'] !== null) {
        $record['working_hours'] = (float) $record['working_hours'];
        $totalWorkingHours += $record['working_hours'];
        $completedDays++;
    }
}
unset($record);

sendJsonResponse([
    'status' => 'success',
    'message' => 'Timecard retrieved successfully.',
    'data' => [
        'user' => [
            'id' => $user['id'],
            'employee_id' => $user['employee_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
        'summary' => [
            'attendance_days' => count($records),
            'completed_days' => $completedDays,
            'total_working_hours' => round($totalWorkingHours, 2),
        ],
        'records' => $records,
    ],
]);
