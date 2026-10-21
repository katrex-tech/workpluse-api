<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$idInput = $_GET['id'] ?? null;
$taskId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($taskId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT
            t.id,
            t.title,
            t.description,
            t.category_id,
            c.name AS category_name,
            c.color AS category_color,
            t.assigned_to,
            assignee.full_name AS assigned_to_name,
            t.assigned_by,
            assigner.full_name AS assigned_by_name,
            t.priority,
            t.status,
            t.start_date,
            t.due_date,
            t.due_time,
            t.created_at,
            t.updated_at
         FROM tasks AS t
         LEFT JOIN task_categories AS c ON c.id = t.category_id
         LEFT JOIN users AS assignee ON assignee.id = t.assigned_to
         LEFT JOIN users AS assigner ON assigner.id = t.assigned_by
         WHERE t.id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $taskId]);
    $task = $query->fetch();
} catch (PDOException $exception) {
    error_log('Task detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve task details.',
        'data' => [],
    ], 500);
}

if ($task === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Task not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Task retrieved successfully.',
    'data' => $task,
]);
