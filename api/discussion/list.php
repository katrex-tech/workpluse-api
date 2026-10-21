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

$search = $_GET['search'] ?? null;
if ($search !== null && !is_string($search)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'search must be a string.',
        'data' => [],
    ], 422);
}

$sql = 'SELECT
            d.id,
            d.title,
            d.description,
            d.created_by,
            u.full_name AS creator_name,
            d.attachment,
            (
                SELECT COUNT(*)
                FROM discussion_comments AS dc
                WHERE dc.discussion_id = d.id
            ) AS total_comments,
            d.created_at
        FROM discussions AS d
        LEFT JOIN users AS u ON u.id = d.created_by';
$parameters = [];

if ($search !== null && trim($search) !== '') {
    $keyword = trim($search);
    if (strlen($keyword) > 100) {
        sendJsonResponse([
            'status' => false,
            'message' => 'search must not exceed 100 characters.',
            'data' => [],
        ], 422);
    }

    $escapedKeyword = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
    $sql .= " WHERE (d.title LIKE :title_search ESCAPE '!'
              OR d.description LIKE :description_search ESCAPE '!')";
    $parameters['title_search'] = '%' . $escapedKeyword . '%';
    $parameters['description_search'] = '%' . $escapedKeyword . '%';
}

$sql .= ' ORDER BY d.created_at DESC, d.id DESC';

try {
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $discussions = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Discussion listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve discussions.',
        'data' => [],
    ], 500);
}

foreach ($discussions as &$discussion) {
    $discussion['total_comments'] = (int) $discussion['total_comments'];
}
unset($discussion);

sendJsonResponse([
    'status' => true,
    'message' => 'Discussions retrieved successfully.',
    'data' => $discussions,
]);
