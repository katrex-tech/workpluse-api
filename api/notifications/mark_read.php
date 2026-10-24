<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

$userId = isset($input['user_id']) ? (int) $input['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

$notifId = isset($input['id']) ? (int) $input['id'] : 0;
$markAll = !empty($input['all']);

try {
    if ($markAll) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute(['user_id' => $userId]);
    } elseif ($notifId > 0) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $notifId, 'user_id' => $userId]);
    } else {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Either notification ID or all=true is required.',
        ], 422);
    }

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $cntStmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $cntStmt->fetchColumn();

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Notification(s) marked as read.',
        'unread_count' => $unreadCount,
    ]);
} catch (PDOException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to update notification: ' . $e->getMessage(),
    ], 500);
}
