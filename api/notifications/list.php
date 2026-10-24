<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

try {
    // 1. Fetch unread count
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $cntStmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $cntStmt->fetchColumn();

    // 2. Fetch notifications list
    $stmt = $pdo->prepare('
        SELECT id, user_id, title, message, is_read, created_at 
        FROM notifications 
        WHERE user_id = :user_id 
        ORDER BY id DESC 
        LIMIT 100
    ');
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($rows as $row) {
        $notifications[] = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'title' => (string) $row['title'],
            'message' => (string) ($row['message'] ?? ''),
            'is_read' => (bool) $row['is_read'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
    ]);
} catch (PDOException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to fetch notifications: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
