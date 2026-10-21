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

$assetIdValue = $_GET['id'] ?? null;
if ($assetIdValue === null) {
    try {
        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Request body must be valid JSON.',
            'data' => [],
        ], 400);
    }

    $assetIdValue = is_array($input) ? ($input['id'] ?? null) : null;
}

$assetId = is_string($assetIdValue) || is_int($assetIdValue)
    ? filter_var($assetIdValue, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($assetId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid asset id is required in the query string or JSON body.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        "UPDATE assets
         SET status = :status, assigned_to = :assigned_to
         WHERE id = :id"
    );
    $query->execute([
        'status' => 'returned',
        'assigned_to' => null,
        'id' => $assetId,
    ]);

    if ($query->rowCount() === 0) {
        $existsQuery = $pdo->prepare('SELECT id FROM assets WHERE id = :id LIMIT 1');
        $existsQuery->execute(['id' => $assetId]);
        if ($existsQuery->fetch() === false) {
            sendJsonResponse([
                'status' => false,
                'message' => 'Asset not found.',
                'data' => [],
            ], 404);
        }
    }
} catch (PDOException $exception) {
    error_log('Asset return failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to mark asset as returned.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Asset marked as returned successfully.',
    'data' => [
        'id' => $assetId,
        'status' => 'returned',
    ],
]);
