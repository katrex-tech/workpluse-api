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

$discussionIdInput = $_GET['id'] ?? null;
$discussionId = is_string($discussionIdInput) || is_int($discussionIdInput)
    ? filter_var($discussionIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($discussionId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $discussionQuery = $pdo->prepare(
        'SELECT
            d.id,
            d.title,
            d.description,
            d.created_by,
            u.full_name AS creator_name,
            u.email AS creator_email,
            u.profile_photo AS creator_profile_photo,
            d.attachment,
            d.created_at
         FROM discussions AS d
         LEFT JOIN users AS u ON u.id = d.created_by
         WHERE d.id = :id
         LIMIT 1'
    );
    $discussionQuery->execute(['id' => $discussionId]);
    $discussion = $discussionQuery->fetch();

    if ($discussion === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Discussion not found.',
            'data' => [],
        ], 404);
    }

    $commentsQuery = $pdo->prepare(
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
    $commentsQuery->execute(['discussion_id' => $discussionId]);
    $comments = $commentsQuery->fetchAll();
} catch (PDOException $exception) {
    error_log('Discussion detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve discussion details.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Discussion retrieved successfully.',
    'data' => [
        'discussion' => $discussion,
        'comments' => $comments,
    ],
]);
