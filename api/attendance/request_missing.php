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

$date = trim($input['date'] ?? date('Y-m-d'));
$checkIn = trim($input['check_in_time'] ?? '10:00:00');
$checkOut = trim($input['check_out_time'] ?? '19:00:00');
$reason = trim($input['reason'] ?? '');

if ($reason === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide a reason for the missing attendance regularization.',
    ], 422);
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO attendance_regularization_requests (user_id, date, check_in_time, check_out_time, reason, status)
        VALUES (:uid, :date, :in_time, :out_time, :reason, 'pending')
    ");
    $stmt->execute([
        'uid' => $userId,
        'date' => $date,
        'in_time' => $checkIn,
        'out_time' => $checkOut,
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
            'Missing Attendance Request',
            "$empName requested attendance regularization for $date ($checkIn - $checkOut). Reason: $reason"
        ]);
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Missing attendance regularization request submitted successfully.',
        'data' => [
            'id' => $requestId,
            'date' => $date,
            'status' => 'pending',
        ],
    ], 201);
} catch (PDOException $e) {
    error_log('Missing attendance request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to submit attendance request: ' . $e->getMessage(),
    ], 500);
}
