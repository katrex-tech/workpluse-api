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

$idInput = $_GET['id'] ?? null;
$circularId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($circularId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT
            c.id,
            c.title,
            c.description,
            c.attachment,
            c.created_by,
            u.full_name AS creator_name,
            u.email AS creator_email,
            u.role AS creator_role,
            u.profile_photo AS creator_profile_photo,
            c.created_at
         FROM circulars AS c
         LEFT JOIN users AS u ON u.id = c.created_by
         WHERE c.id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $circularId]);
    $circular = $query->fetch();
} catch (PDOException $exception) {
    error_log('Circular detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve circular details.',
        'data' => [],
    ], 500);
}

if ($circular === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Circular not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Circular retrieved successfully.',
    'data' => $circular,
]);
