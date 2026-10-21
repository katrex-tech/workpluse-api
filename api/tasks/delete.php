<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'DELETE') {
    header('Allow: DELETE, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$idInput = $_GET['id'] ?? null;
if ($idInput === null) {
    try {
        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Request body must be valid JSON.',
            'data' => [],
        ], 400);
    }
    $idInput = is_array($input) ? ($input['id'] ?? null) : null;
}

$taskId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($taskId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid task id is required in the query string or JSON body.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare('DELETE FROM tasks WHERE id = :id');
    $query->execute(['id' => $taskId]);
    if ($query->rowCount() === 0) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Task not found.',
            'data' => [],
        ], 404);
    }
} catch (PDOException $exception) {
    error_log('Task deletion failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to delete task.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Task deleted successfully.',
    'data' => [
        'id' => $taskId,
    ],
]);
