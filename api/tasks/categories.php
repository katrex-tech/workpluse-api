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

try {
    $query = $pdo->query(
        "SELECT
            c.id,
            c.name,
            c.color,
            COUNT(t.id) AS total_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress,
            COALESCE(SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
            COALESCE(SUM(CASE WHEN t.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN t.status = 'on_hold' THEN 1 ELSE 0 END), 0) AS on_hold
         FROM task_categories AS c
         LEFT JOIN tasks AS t ON t.category_id = c.id
         GROUP BY c.id, c.name, c.color
         ORDER BY c.name ASC"
    );
    $categories = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Task category listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve task categories.',
        'data' => [],
    ], 500);
}

foreach ($categories as &$category) {
    foreach (['total_tasks', 'pending', 'in_progress', 'completed', 'cancelled', 'on_hold'] as $countField) {
        $category[$countField] = (int) $category[$countField];
    }
}
unset($category);

sendJsonResponse([
    'status' => true,
    'message' => 'Task categories retrieved successfully.',
    'data' => $categories,
]);
