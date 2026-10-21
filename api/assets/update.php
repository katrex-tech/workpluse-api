<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'PUT') {
    header('Allow: PUT, OPTIONS');
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

$assetIdInput = $input['id'] ?? null;
$assetId = is_string($assetIdInput) || is_int($assetIdInput)
    ? filter_var($assetIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$assetName = isset($input['asset_name']) && is_string($input['asset_name'])
    ? trim($input['asset_name'])
    : '';
$assetType = isset($input['asset_type']) && is_string($input['asset_type'])
    ? trim($input['asset_type'])
    : '';
$serialNumber = isset($input['serial_number']) && is_string($input['serial_number'])
    ? trim($input['serial_number'])
    : '';
$assignedToInput = $input['assigned_to'] ?? null;
$assignedTo = $assignedToInput === null
    ? null
    : (is_string($assignedToInput) || is_int($assignedToInput)
        ? filter_var($assignedToInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
        ])
        : false);
$status = isset($input['status']) && is_string($input['status'])
    ? trim($input['status'])
    : '';
$image = $input['image'] ?? null;

if (
    $assetId === false
    || $assetName === ''
    || strlen($assetName) > 255
    || $assetType === ''
    || strlen($assetType) > 100
    || $serialNumber === ''
    || strlen($serialNumber) > 100
    || ($assignedToInput !== null && $assignedTo === false)
    || $status === ''
    || strlen($status) > 50
    || ($image !== null && (!is_string($image) || strlen($image) > 2048))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid id, asset_name, asset_type, serial_number, and status are required. assigned_to must be null or a positive integer, and image must be a string when provided.',
        'data' => [],
    ], 422);
}

try {
    $assetQuery = $pdo->prepare('SELECT id FROM assets WHERE id = :id LIMIT 1');
    $assetQuery->execute(['id' => $assetId]);
    if ($assetQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Asset not found.',
            'data' => [],
        ], 404);
    }

    if ($assignedTo !== null) {
        $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $userQuery->execute(['id' => $assignedTo]);
        if ($userQuery->fetch() === false) {
            sendJsonResponse([
                'status' => false,
                'message' => 'Assigned user not found.',
                'data' => [],
            ], 404);
        }
    }

    $sql = 'UPDATE assets
            SET asset_name = :asset_name,
                asset_type = :asset_type,
                serial_number = :serial_number,
                assigned_to = :assigned_to,
                status = :status';
    $parameters = [
        'asset_name' => $assetName,
        'asset_type' => $assetType,
        'serial_number' => $serialNumber,
        'assigned_to' => $assignedTo,
        'status' => $status,
        'id' => $assetId,
    ];

    if (array_key_exists('image', $input)) {
        $sql .= ', image = :image';
        $parameters['image'] = $image;
    }
    $sql .= ' WHERE id = :id';

    $update = $pdo->prepare($sql);
    $update->execute($parameters);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        sendJsonResponse([
            'status' => false,
            'message' => 'Asset could not be assigned or its serial number is already in use.',
            'data' => [],
        ], 409);
    }

    error_log('Asset update failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to update asset.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Asset updated successfully.',
    'data' => [
        'id' => $assetId,
    ],
]);
