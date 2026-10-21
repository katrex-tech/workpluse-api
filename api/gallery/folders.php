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
            gf.id,
            gf.folder_name,
            gf.cover_image,
            (
                SELECT COUNT(*)
                FROM gallery_images AS gi
                WHERE gi.folder_id = gf.id
            ) AS total_images,
            gf.created_at
         FROM gallery_folders AS gf
         ORDER BY gf.created_at DESC, gf.id DESC'
    );
    $folders = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Gallery folder listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve gallery folders.',
        'data' => [],
    ], 500);
}

foreach ($folders as &$folder) {
    $folder['total_images'] = (int) $folder['total_images'];
}
unset($folder);

sendJsonResponse([
    'status' => true,
    'message' => 'Gallery folders retrieved successfully.',
    'data' => $folders,
]);
