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

$folderName = isset($input['folder_name']) && is_string($input['folder_name'])
    ? trim($input['folder_name'])
    : '';
$createdByInput = $input['created_by'] ?? null;
$createdBy = is_string($createdByInput) || is_int($createdByInput)
    ? filter_var($createdByInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$coverImage = $input['cover_image'] ?? null;

if (
    $folderName === ''
    || strlen($folderName) > 255
    || $createdBy === false
    || ($coverImage !== null && (!is_string($coverImage) || strlen($coverImage) > 2048))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid folder_name and created_by are required. cover_image must be a string when provided.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $createdBy]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Creator not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO gallery_folders (folder_name, cover_image, created_by)
         VALUES (:folder_name, :cover_image, :created_by)'
    );
    $insert->execute([
        'folder_name' => $folderName,
        'cover_image' => $coverImage,
        'created_by' => $createdBy,
    ]);
    $folderId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Gallery folder creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to create gallery folder.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Gallery folder created successfully.',
    'data' => [
        'id' => $folderId,
        'folder_name' => $folderName,
    ],
], 201);
