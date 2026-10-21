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
$createdByInput = $input['created_by'] ?? null;
$createdBy = is_string($createdByInput) || is_int($createdByInput)
    ? filter_var($createdByInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$attachment = $input['attachment'] ?? null;

if (
    $title === ''
    || strlen($title) > 255
    || $description === ''
    || $createdBy === false
    || ($attachment !== null && (!is_string($attachment) || strlen($attachment) > 2048))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid title, description, and created_by are required. attachment must be a string when provided.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $createdBy]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Discussion creator not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO discussions (title, description, created_by, attachment)
         VALUES (:title, :description, :created_by, :attachment)'
    );
    $insert->execute([
        'title' => $title,
        'description' => $description,
        'created_by' => $createdBy,
        'attachment' => $attachment,
    ]);
    $discussionId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Discussion creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to create discussion.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Discussion created successfully.',
    'data' => [
        'id' => $discussionId,
    ],
], 201);
