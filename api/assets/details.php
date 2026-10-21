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

$assetIdInput = $_GET['id'] ?? null;
$assetId = is_string($assetIdInput) || is_int($assetIdInput)
    ? filter_var($assetIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($assetId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT
            id,
            asset_name,
            asset_type,
            serial_number,
            image,
            assigned_to,
            assigned_date,
            status
         FROM assets
         WHERE id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $assetId]);
    $asset = $query->fetch();
} catch (PDOException $exception) {
    error_log('Asset detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve asset details.',
        'data' => [],
    ], 500);
}

if ($asset === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Asset not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Asset retrieved successfully.',
    'data' => $asset,
]);
