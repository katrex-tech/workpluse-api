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

$keyword = $_GET['search'] ?? null;
if (!is_string($keyword) || trim($keyword) === '') {
    sendJsonResponse([
        'status' => false,
        'message' => 'A non-empty search query parameter is required.',
        'data' => [],
    ], 422);
}

$keyword = trim($keyword);
if (strlen($keyword) > 100) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Search query must not exceed 100 characters.',
        'data' => [],
    ], 422);
}

$escapedKeyword = str_replace(
    ['!', '%', '_'],
    ['!!', '!%', '!_'],
    $keyword
);
$searchTerm = '%' . $escapedKeyword . '%';

try {
    $query = $pdo->prepare(
        "SELECT
            id,
            full_name,
            email,
            designation,
            department,
            profile_photo,
            role,
            status
         FROM users
         WHERE role = :role
           AND status = :status
           AND (
                full_name LIKE :name_search ESCAPE '!'
                OR email LIKE :email_search ESCAPE '!'
                OR designation LIKE :designation_search ESCAPE '!'
                OR department LIKE :department_search ESCAPE '!'
           )
         ORDER BY full_name ASC"
    );
    $query->execute([
        'role' => 'employee',
        'status' => 'active',
        'name_search' => $searchTerm,
        'email_search' => $searchTerm,
        'designation_search' => $searchTerm,
        'department_search' => $searchTerm,
    ]);
    $teamMembers = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Team member search failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to search team members.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Team member search completed successfully.',
    'data' => $teamMembers,
]);
