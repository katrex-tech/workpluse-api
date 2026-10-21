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

$discussionIdInput = $_GET['discussion_id'] ?? null;
$discussionId = is_string($discussionIdInput) || is_int($discussionIdInput)
    ? filter_var($discussionIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($discussionId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid discussion_id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $discussionQuery = $pdo->prepare(
        'SELECT id FROM discussions WHERE id = :id LIMIT 1'
    );
    $discussionQuery->execute(['id' => $discussionId]);
    if ($discussionQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Discussion not found.',
            'data' => [],
        ], 404);
    }

    $query = $pdo->prepare(
        'SELECT
            dc.id,
            dc.discussion_id,
            dc.user_id,
            u.full_name AS user_name,
            u.profile_photo,
            dc.comment,
            dc.created_at
         FROM discussion_comments AS dc
         LEFT JOIN users AS u ON u.id = dc.user_id
         WHERE dc.discussion_id = :discussion_id
         ORDER BY dc.created_at ASC, dc.id ASC'
    );
    $query->execute(['discussion_id' => $discussionId]);
    $comments = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Discussion comment listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve discussion comments.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Discussion comments retrieved successfully.',
    'data' => $comments,
]);
