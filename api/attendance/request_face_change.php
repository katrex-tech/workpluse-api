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
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = getAuthenticatedUserId(true);
$user = getAuthenticatedUser(true);

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
$reason = trim($input['reason'] ?? '');

if ($reason === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide a reason for the face re-registration request.',
    ], 422);
}

try {
    // Check if there is already a pending request
    $pendingStmt = $pdo->prepare("SELECT id FROM face_change_requests WHERE user_id = :uid AND status = 'pending' LIMIT 1");
    $pendingStmt->execute(['uid' => $userId]);
    if ($pendingStmt->fetch()) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You already have a pending face change request awaiting HR approval.',
        ], 409);
    }

    $stmt = $pdo->prepare("
        INSERT INTO face_change_requests (user_id, reason, status)
        VALUES (:uid, :reason, 'pending')
    ");
    $stmt->execute([
        'uid' => $userId,
        'reason' => $reason,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    // Create notifications for HR & Super Admin
    $adminsStmt = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'hr') AND status = 'active'");
    $adminIds = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");

    $empName = $user['full_name'] ?? 'An employee';
    foreach ($adminIds as $adminId) {
        $notifStmt->execute([
            $adminId,
            'Face Change Request',
            "$empName requested face biometrics reset / re-registration. Reason: $reason"
        ]);
    }

    // Dispatch FCM Push Notification with Sound to HR & Super Admin
    require_once __DIR__ . '/../../config/fcm_helper.php';
    sendFcmToUsers(
        $pdo,
        $adminIds,
        'Face Change Request 📸',
        "$empName requested face biometrics reset. Reason: $reason",
        [
            'type' => 'face_change',
            'id' => (string) $requestId,
            'user_id' => (string) $userId,
        ]
    );

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Face change request submitted successfully. Once approved by HR, you can scan a new face.',
        'data' => [
            'id' => $requestId,
            'status' => 'pending',
        ],
    ], 201);
} catch (PDOException $e) {
    error_log('Face change request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to submit face change request: ' . $e->getMessage(),
    ], 500);
}
