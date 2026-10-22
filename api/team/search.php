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
            employee_id,
            full_name,
            email,
            mobile,
            designation,
            department,
            profile_photo,
            role,
            status
         FROM users
         WHERE status = :status
           AND (
                full_name LIKE :name_search ESCAPE '!'
                OR email LIKE :email_search ESCAPE '!'
                OR designation LIKE :designation_search ESCAPE '!'
                OR department LIKE :department_search ESCAPE '!'
           )
         ORDER BY full_name ASC"
    );
    $query->execute([
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

foreach ($teamMembers as &$member) {
    $nameParts = explode(' ', trim($member['full_name']));
    $initials = '';
    if (isset($nameParts[0][0])) $initials .= strtoupper($nameParts[0][0]);
    if (isset($nameParts[1][0])) $initials .= strtoupper($nameParts[1][0]);
    $member['initials'] = $initials ?: 'WP';
    if (empty($member['designation'])) {
        $member['designation'] = 'Team Member';
    }
    if (empty($member['department'])) {
        $member['department'] = 'Engineering';
    }
}
unset($member);

sendJsonResponse([
    'status' => true,
    'message' => 'Team member search completed successfully.',
    'data' => $teamMembers,
]);
