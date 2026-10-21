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

$title = isset($input['title']) && is_string($input['title'])
    ? trim($input['title'])
    : '';
$description = isset($input['description']) && is_string($input['description'])
    ? trim($input['description'])
    : '';
$categoryInput = $input['category_id'] ?? null;
$categoryId = is_string($categoryInput) || is_int($categoryInput)
    ? filter_var($categoryInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$assigneeInput = $input['assigned_to'] ?? null;
$assignedTo = is_string($assigneeInput) || is_int($assigneeInput)
    ? filter_var($assigneeInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$assignerInput = $input['assigned_by'] ?? null;
$assignedBy = is_string($assignerInput) || is_int($assignerInput)
    ? filter_var($assignerInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$priority = isset($input['priority']) && is_string($input['priority'])
    ? trim($input['priority'])
    : '';
$startDate = $input['start_date'] ?? null;
$dueDate = $input['due_date'] ?? null;
$dueTime = $input['due_time'] ?? null;
$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$isValidTime = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $time = DateTimeImmutable::createFromFormat('!H:i:s', $value);
    return $time !== false && $time->format('H:i:s') === $value;
};

if (
    $title === ''
    || strlen($title) > 255
    || $categoryId === false
    || $assignedTo === false
    || $assignedBy === false
    || $priority === ''
    || strlen($priority) > 50
    || !$isValidDate($startDate)
    || !$isValidDate($dueDate)
    || ($dueTime !== null && !$isValidTime($dueTime))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid title, category_id, assigned_to, assigned_by, priority, start_date, and due_date are required. due_time must use HH:MM:SS when provided.',
        'data' => [],
    ], 422);
}

if ($startDate > $dueDate) {
    sendJsonResponse([
        'status' => false,
        'message' => 'start_date must be on or before due_date.',
        'data' => [],
    ], 422);
}

try {
    $categoryQuery = $pdo->prepare(
        'SELECT id FROM task_categories WHERE id = :id LIMIT 1'
    );
    $categoryQuery->execute(['id' => $categoryId]);
    if ($categoryQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Task category not found.',
            'data' => [],
        ], 404);
    }

    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $assignedTo]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Assigned user not found.',
            'data' => [],
        ], 404);
    }

    $userQuery->execute(['id' => $assignedBy]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Assigning user not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO tasks
            (title, description, category_id, assigned_to, assigned_by, priority, status, start_date, due_date, due_time)
         VALUES
            (:title, :description, :category_id, :assigned_to, :assigned_by, :priority, :status, :start_date, :due_date, :due_time)'
    );
    $insert->execute([
        'title' => $title,
        'description' => $description,
        'category_id' => $categoryId,
        'assigned_to' => $assignedTo,
        'assigned_by' => $assignedBy,
        'priority' => $priority,
        'status' => 'pending',
        'start_date' => $startDate,
        'due_date' => $dueDate,
        'due_time' => $dueTime,
    ]);
    $taskId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Task creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to create task.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Task created successfully.',
    'data' => [
        'id' => $taskId,
    ],
], 201);
