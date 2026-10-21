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

try {
    $query = $pdo->query(
        'SELECT
            c.id,
            c.title,
            c.description,
            c.attachment,
            c.created_by,
            u.full_name AS creator_name,
            c.created_at
         FROM circulars AS c
         LEFT JOIN users AS u ON u.id = c.created_by
         ORDER BY c.created_at DESC, c.id DESC'
    );
    $circulars = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Circular listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve circulars.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Circulars retrieved successfully.',
    'data' => $circulars,
]);
