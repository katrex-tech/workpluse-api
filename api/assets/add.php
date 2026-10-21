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
$assignedTo = is_string($assignedToInput) || is_int($assignedToInput)
    ? filter_var($assignedToInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$assignedDate = $input['assigned_date'] ?? null;
$image = $input['image'] ?? null;

$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (
    $assetName === ''
    || strlen($assetName) > 255
    || $assetType === ''
    || strlen($assetType) > 100
    || $serialNumber === ''
    || strlen($serialNumber) > 100
    || $assignedTo === false
    || !$isValidDate($assignedDate)
    || ($image !== null && (!is_string($image) || strlen($image) > 2048))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid asset_name, asset_type, serial_number, assigned_to, and assigned_date are required. image must be a string when provided.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $assignedTo]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Assigned user not found.',
            'data' => [],
        ], 404);
    }

    $query = $pdo->prepare(
        'INSERT INTO assets
            (asset_name, asset_type, serial_number, image, assigned_to, assigned_date, status)
         VALUES
            (:asset_name, :asset_type, :serial_number, :image, :assigned_to, :assigned_date, :status)'
    );
    $query->execute([
        'asset_name' => $assetName,
        'asset_type' => $assetType,
        'serial_number' => $serialNumber,
        'image' => $image,
        'assigned_to' => $assignedTo,
        'assigned_date' => $assignedDate,
        'status' => 'assigned',
    ]);
    $assetId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        sendJsonResponse([
            'status' => false,
            'message' => 'Asset could not be assigned or its serial number is already in use.',
            'data' => [],
        ], 409);
    }

    error_log('Asset creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to create asset.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Asset created successfully.',
    'data' => [
        'id' => $assetId,
    ],
], 201);
