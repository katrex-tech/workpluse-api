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
    $query = $pdo->prepare(
        "SELECT
            id,
            full_name,
            designation,
            department,
            profile_photo,
            role,
            status
         FROM users
         WHERE status = :status AND role = :role
         ORDER BY full_name ASC"
    );
    $query->execute([
        'status' => 'active',
        'role' => 'employee',
    ]);
    $teamMembers = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Team member list failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve team members.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Team members retrieved successfully.',
    'data' => $teamMembers,
]);
