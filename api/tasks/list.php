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

$filters = [];
$parameters = [];
$integerFilters = [
    'category_id' => 't.category_id',
    'assigned_to' => 't.assigned_to',
];

foreach ($integerFilters as $key => $column) {
    if (!array_key_exists($key, $_GET)) {
        continue;
    }

    $value = $_GET[$key];
    $validated = is_string($value) || is_int($value)
        ? filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($validated === false) {
        sendJsonResponse([
            'status' => false,
            'message' => $key . ' must be a positive integer.',
            'data' => [],
        ], 422);
    }

    $placeholder = ':' . $key;
    $filters[] = $column . ' = ' . $placeholder;
    $parameters[$key] = $validated;
}

$allowedStatuses = ['pending', 'in_progress', 'completed', 'cancelled', 'on_hold'];
if (array_key_exists('status', $_GET)) {
    $status = $_GET['status'];
    if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
        sendJsonResponse([
            'status' => false,
            'message' => 'status must be pending, in_progress, completed, cancelled, or on_hold.',
            'data' => [],
        ], 422);
    }

    $filters[] = 't.status = :status';
    $parameters['status'] = $status;
}

if (array_key_exists('priority', $_GET)) {
    $priority = $_GET['priority'];
    if (!is_string($priority) || trim($priority) === '' || strlen($priority) > 50) {
        sendJsonResponse([
            'status' => false,
            'message' => 'priority must be a non-empty string of at most 50 characters.',
            'data' => [],
        ], 422);
    }

    $filters[] = 't.priority = :priority';
    $parameters['priority'] = trim($priority);
}

$sql = 'SELECT
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
        LEFT JOIN users AS assigner ON assigner.id = t.assigned_by';
if ($filters !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $filters);
}
$sql .= ' ORDER BY t.created_at DESC, t.id DESC';

try {
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $tasks = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Task listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve tasks.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Tasks retrieved successfully.',
    'data' => $tasks,
]);
