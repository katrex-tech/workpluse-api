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
        'message' => 'Method not allowed. GET required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = getAuthenticatedUserId();
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    // 1. Fetch user field sales status and target
    $uStmt = $pdo->prepare('
        SELECT id, role, is_field_sales, daily_meeting_target 
        FROM users 
        WHERE id = :uid 
        LIMIT 1
    ');
    $uStmt->execute(['uid' => $userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    $isFieldSales = !empty($user['is_field_sales']) || strtolower($user['role'] ?? '') === 'sales_executive';

    // If NOT field sales, always allow standard logout
    if (!$isFieldSales) {
        sendJsonResponse([
            'status' => 'success',
            'message' => 'Standard employee: no meeting quota required.',
            'data' => [
                'is_field_sales' => false,
                'can_logout' => true,
                'target_meetings' => 0,
                'completed_meetings' => 0,
                'approved_meetings' => 0,
                'pending_meetings' => 0,
                'rejected_meetings' => 0,
                'has_approved_exception' => false,
                'has_pending_request' => false,
                'logout_request' => null,
            ],
        ], 200);
    }

    $target = 3; // Static 3 daily meetings target

    // 2. Count meetings for today
    $mStmt = $pdo->prepare('
        SELECT status, COUNT(*) as cnt 
        FROM sales_meetings 
        WHERE user_id = :uid AND DATE(check_in_time) = :dt 
        GROUP BY status
    ');
    $mStmt->execute(['uid' => $userId, 'dt' => $date]);
    $counts = $mStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $approved = (int)($counts['approved'] ?? 0);
    $pending = (int)($counts['pending'] ?? 0);
    $rejected = (int)($counts['rejected'] ?? 0);
    $totalCompleted = $approved + $pending + $rejected;

    // 3. Check if there is an approved or pending logout request for today
    $reqStmt = $pdo->prepare('
        SELECT id, reason, status, review_remarks, reviewed_at, created_at 
        FROM sales_logout_requests 
        WHERE user_id = :uid AND request_date = :dt 
        ORDER BY id DESC 
        LIMIT 1
    ');
    $reqStmt->execute(['uid' => $userId, 'dt' => $date]);
    $logoutRequest = $reqStmt->fetch(PDO::FETCH_ASSOC);

    $hasApprovedException = ($logoutRequest && $logoutRequest['status'] === 'approved');
    $hasPendingRequest = ($logoutRequest && $logoutRequest['status'] === 'pending');

    $canLogout = ($approved >= $target) || $hasApprovedException;

    $reason = '';
    if ($canLogout) {
        $reason = $hasApprovedException 
            ? 'Early logout approved by Administrator.' 
            : 'Daily meeting target achieved successfully!';
    } else {
        $remaining = $target - $approved;
        $reason = "Target Incomplete: You have completed $approved of $target approved meetings ($remaining more needed). Please request admin approval to log out early.";
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => $canLogout ? 'Logout eligible.' : 'Logout restricted: Target pending.',
        'data' => [
            'is_field_sales' => true,
            'can_logout' => $canLogout,
            'date' => $date,
            'target_meetings' => $target,
            'completed_meetings' => $totalCompleted,
            'approved_meetings' => $approved,
            'pending_meetings' => $pending,
            'rejected_meetings' => $rejected,
            'has_approved_exception' => $hasApprovedException,
            'has_pending_request' => $hasPendingRequest,
            'reason' => $reason,
            'logout_request' => $logoutRequest ?: null,
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('logout_status error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to check logout eligibility: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
