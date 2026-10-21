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

$folderIdInput = $_GET['folder_id'] ?? null;
$folderId = is_string($folderIdInput) || is_int($folderIdInput)
    ? filter_var($folderIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($folderId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid folder_id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $folderQuery = $pdo->prepare(
        'SELECT id FROM gallery_folders WHERE id = :id LIMIT 1'
    );
    $folderQuery->execute(['id' => $folderId]);
    if ($folderQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Gallery folder not found.',
            'data' => [],
        ], 404);
    }

    $query = $pdo->prepare(
        'SELECT id, folder_id, image_url, uploaded_by, created_at
         FROM gallery_images
         WHERE folder_id = :folder_id
         ORDER BY created_at DESC, id DESC'
    );
    $query->execute(['folder_id' => $folderId]);
    $images = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Gallery image listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve gallery images.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Gallery images retrieved successfully.',
    'data' => $images,
]);
