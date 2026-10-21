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
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($userId === false || !$isValidDate($fromDate) || !$isValidDate($toDate)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A valid user_id, from_date, and to_date are required (dates must use YYYY-MM-DD).',
        'data' => null,
    ], 422);
}

if ($fromDate > $toDate) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'from_date must be on or before to_date.',
        'data' => null,
    ], 422);
}

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
        'SELECT id, attendance_date, punch_in, punch_out, working_hours
         FROM attendance
         WHERE user_id = :user_id
           AND attendance_date BETWEEN :from_date AND :to_date
         ORDER BY attendance_date DESC'
    );
    $query->execute([
        'user_id' => $userId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ]);
    $records = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Attendance history lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to retrieve attendance history.',
        'data' => null,
    ], 500);
}

foreach ($records as &$record) {
    $record['working_hours'] = $record['working_hours'] === null
        ? null
        : (float) $record['working_hours'];
}
unset($record);

sendJsonResponse([
    'status' => 'success',
    'message' => 'Attendance history retrieved successfully.',
    'data' => [
        'user_id' => $userId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'records' => $records,
    ],
]);
