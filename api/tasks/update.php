<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['PUT', 'POST'], true)) {
    header('Allow: PUT, POST, OPTIONS');
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

$idInput = $input['id'] ?? null;
$taskId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($taskId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid task id is required.',
        'data' => [],
    ], 422);
}

$allowedStatuses = ['pending', 'in_progress', 'completed', 'cancelled', 'on_hold'];
$allowedFields = [
    'title' => static function ($value) {
        return is_string($value) && trim($value) !== '' && strlen(trim($value)) <= 255
            ? trim($value)
            : false;
    },
    'description' => static function ($value) {
        return is_string($value) ? trim($value) : false;
    },
    'priority' => static function ($value) {
        return is_string($value) && trim($value) !== '' && strlen(trim($value)) <= 50
            ? trim($value)
            : false;
    },
    'status' => static function ($value) use ($allowedStatuses) {
        return is_string($value) && in_array($value, $allowedStatuses, true) ? $value : false;
    },
    'category_id' => static function ($value) {
        return is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
    },
    'assigned_to' => static function ($value) {
        return is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
    },
    'assigned_by' => static function ($value) {
        return is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
    },
    'start_date' => static function ($value) {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : false;
    },
    'due_date' => static function ($value) {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : false;
    },
    'due_time' => static function ($value) {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat('!H:i:s', $value);
        return $time !== false && $time->format('H:i:s') === $value ? $value : false;
    },
];

$updates = [];
$parameters = ['id' => $taskId];
foreach ($allowedFields as $field => $validator) {
    if (!array_key_exists($field, $input)) {
        continue;
    }

    $value = $validator($input[$field]);
    if ($value === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Invalid value for ' . $field . '.',
            'data' => [],
        ], 422);
    }

    $updates[] = $field . ' = :' . $field;
    $parameters[$field] = $value;
}

if ($updates === []) {
    sendJsonResponse([
        'status' => false,
        'message' => 'At least one task field must be provided for update.',
        'data' => [],
    ], 422);
}

try {
    $existsQuery = $pdo->prepare('SELECT id FROM tasks WHERE id = :id LIMIT 1');
    $existsQuery->execute(['id' => $taskId]);
    if ($existsQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Task not found.',
            'data' => [],
        ], 404);
    }

    if (isset($parameters['category_id'])) {
        $categoryQuery = $pdo->prepare(
            'SELECT id FROM task_categories WHERE id = :id LIMIT 1'
        );
        $categoryQuery->execute(['id' => $parameters['category_id']]);
        if ($categoryQuery->fetch() === false) {
            sendJsonResponse([
                'status' => false,
                'message' => 'Task category not found.',
                'data' => [],
            ], 404);
        }
    }

    foreach (['assigned_to', 'assigned_by'] as $userField) {
        if (isset($parameters[$userField])) {
            $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $userQuery->execute(['id' => $parameters[$userField]]);
            if ($userQuery->fetch() === false) {
                sendJsonResponse([
                    'status' => false,
                    'message' => 'User specified by ' . $userField . ' was not found.',
                    'data' => [],
                ], 404);
            }
        }
    }

    $update = $pdo->prepare(
        'UPDATE tasks SET ' . implode(', ', $updates) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $update->execute($parameters);
} catch (PDOException $exception) {
    error_log('Task update failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to update task.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Task updated successfully.',
    'data' => [
        'id' => $taskId,
    ],
]);
