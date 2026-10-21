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

$userId = null;
if (array_key_exists('user_id', $_GET)) {
    $userIdInput = $_GET['user_id'];
    $userId = is_string($userIdInput) || is_int($userIdInput)
        ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($userId === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'user_id must be a positive integer.',
            'data' => [],
        ], 422);
    }
}

try {
    $sql = 'SELECT
                a.id,
                a.asset_name,
                a.asset_type,
                a.serial_number,
                a.image,
                a.assigned_to,
                a.assigned_date,
                a.status
            FROM assets AS a';
    $parameters = [];

    if ($userId !== null) {
        $sql .= ' WHERE a.assigned_to = :user_id';
        $parameters['user_id'] = $userId;
    }
    $sql .= ' ORDER BY a.asset_name ASC, a.id ASC';

    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $assets = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Asset list failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve assets.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Assets retrieved successfully.',
    'data' => $assets,
]);
