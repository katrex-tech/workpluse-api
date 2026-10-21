<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be valid JSON.',
        'data' => [],
    ], 400);
}

if (!is_array($input)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be a JSON object.',
        'data' => [],
    ], 400);
}

$discussionIdInput = $input['discussion_id'] ?? null;
$discussionId = is_string($discussionIdInput) || is_int($discussionIdInput)
    ? filter_var($discussionIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$userIdInput = $input['user_id'] ?? null;
$userId = is_string($userIdInput) || is_int($userIdInput)
    ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$comment = isset($input['comment']) && is_string($input['comment'])
    ? trim($input['comment'])
    : '';

if ($discussionId === false || $userId === false || $comment === '') {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid discussion_id, user_id, and a non-empty comment are required.',
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

    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO discussion_comments (discussion_id, user_id, comment)
         VALUES (:discussion_id, :user_id, :comment)'
    );
    $insert->execute([
        'discussion_id' => $discussionId,
        'user_id' => $userId,
        'comment' => $comment,
    ]);
    $commentId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Discussion comment creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to add discussion comment.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Comment added successfully.',
    'data' => [
        'id' => $commentId,
        'discussion_id' => $discussionId,
        'user_id' => $userId,
    ],
], 201);
