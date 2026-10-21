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

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($userId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        "SELECT
            employee_id,
            full_name,
            email,
            mobile,
            profile_photo,
            department,
            designation,
            role,
            employment_type,
            date_of_joining,
            work_location,
            status
         FROM users
         WHERE id = :id AND role = :role
         LIMIT 1"
    );
    $query->execute([
        'id' => $userId,
        'role' => 'employee',
    ]);
    $teamMember = $query->fetch();
} catch (PDOException $exception) {
    error_log('Team member detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve team member details.',
        'data' => [],
    ], 500);
}

if ($teamMember === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Team member not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Team member retrieved successfully.',
    'data' => $teamMember,
]);
