<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be valid JSON.',
        'data' => [],
    ], 400);
}

if (!is_array($input)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be a JSON object.',
        'data' => [],
    ], 400);
}

$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$leaveType = isset($input['leave_type']) && is_string($input['leave_type'])
    ? trim($input['leave_type'])
    : '';
$fromDate = $input['from_date'] ?? null;
$toDate = $input['to_date'] ?? null;
$reason = isset($input['reason']) && is_string($input['reason'])
    ? trim($input['reason'])
    : '';
$halfDayValue = $input['half_day'] ?? false;

$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$halfDay = filter_var($halfDayValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if (
    $userId === false
    || $leaveType === ''
    || strlen($leaveType) > 100
    || !$isValidDate($fromDate)
    || !$isValidDate($toDate)
    || $reason === ''
    || $halfDay === null
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid user_id, leave_type, from_date, to_date, half_day, and reason are required.',
        'data' => [],
    ], 422);
}

if ($fromDate > $toDate) {
    sendJsonResponse([
        'status' => false,
        'message' => 'from_date must be on or before to_date.',
        'data' => [],
    ], 422);
}

if ($halfDay && $fromDate !== $toDate) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A half-day leave must have the same from_date and to_date.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1');
    $userQuery->execute(['user_id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $typeQuery = $pdo->prepare(
        'SELECT id, name FROM leave_types WHERE name = :name LIMIT 1'
    );
    $typeQuery->execute(['name' => $leaveType]);
    $type = $typeQuery->fetch();
    if ($type === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Leave type not found.',
            'data' => [],
        ], 422);
    }

    $insert = $pdo->prepare(
        'INSERT INTO leave_applications
            (user_id, leave_type_id, from_date, to_date, half_day, reason, status)
         VALUES
            (:user_id, :leave_type_id, :from_date, :to_date, :half_day, :reason, :status)'
    );
    $insert->execute([
        'user_id' => $userId,
        'leave_type_id' => $type['id'],
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'half_day' => $halfDay ? 1 : 0,
        'reason' => $reason,
        'status' => 'pending',
    ]);
    $applicationId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Leave application creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to submit leave application.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Leave application submitted successfully.',
    'data' => [
        'id' => $applicationId,
        'user_id' => $userId,
        'leave_type' => $type['name'],
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'half_day' => $halfDay,
        'status' => 'pending',
    ],
], 201);
