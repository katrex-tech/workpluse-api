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

$imageIdInput = $_GET['id'] ?? null;
$imageId = is_string($imageIdInput) || is_int($imageIdInput)
    ? filter_var($imageIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($imageId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT id, folder_id, image_url, uploaded_by, created_at
         FROM gallery_images
         WHERE id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $imageId]);
    $image = $query->fetch();
} catch (PDOException $exception) {
    error_log('Gallery image detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve gallery image details.',
        'data' => [],
    ], 500);
}

if ($image === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Gallery image not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Gallery image retrieved successfully.',
    'data' => $image,
]);
