<?php

require_once __DIR__ . '/../../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth_check.php';

// Only Admin, Sales Head, Super Admin, HR can review meetings
$caller = requireRole(['admin', 'super_admin', 'sales_head', 'hr']);
$callerId = (int)$caller['id'];

$rawBody = file_get_contents('php://input');
try {
    $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be valid JSON.',
        'data' => null,
    ], 400);
}

$meetingId = isset($input['meeting_id']) ? (int)$input['meeting_id'] : 0;
$action = strtolower(trim($input['action'] ?? ''));
$remarks = trim($input['review_remarks'] ?? ($input['remarks'] ?? ''));

if ($meetingId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Valid meeting_id and action (approve / reject) are required.',
        'data' => null,
    ], 422);
}

$newStatus = ($action === 'approve') ? 'approved' : 'rejected';

try {
    // 1. Verify meeting exists
    $checkStmt = $pdo->prepare('SELECT id, user_id, client_name, status, check_in_time FROM sales_meetings WHERE id = :id LIMIT 1');
    $checkStmt->execute(['id' => $meetingId]);
    $meeting = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Meeting record not found.',
            'data' => null,
        ], 404);
    }

    // 2. Update status and review log
    $updateStmt = $pdo->prepare('
        UPDATE sales_meetings
        SET status = :st,
            reviewed_by = :reviewer,
            review_remarks = :remarks,
            reviewed_at = NOW()
        WHERE id = :id
    ');
    $updateStmt->execute([
        'st' => $newStatus,
        'reviewer' => $callerId,
        'remarks' => $remarks ?: ($action === 'approve' ? 'Meeting approved by supervisor.' : 'Meeting rejected.'),
        'id' => $meetingId,
    ]);

    // 3. Count updated approved meetings for this executive today
    $execUserId = (int)$meeting['user_id'];
    $today = date('Y-m-d', strtotime($meeting['check_in_time']));

    $countStmt = $pdo->prepare('
        SELECT COUNT(*) as approved_count 
        FROM sales_meetings 
        WHERE user_id = :uid AND status = "approved" AND DATE(check_in_time) = :dt
    ');
    $countStmt->execute(['uid' => $execUserId, 'dt' => $today]);
    $approvedCount = (int)$countStmt->fetchColumn();

    $targetStmt = $pdo->prepare('SELECT daily_meeting_target FROM users WHERE id = :uid LIMIT 1');
    $targetStmt->execute(['uid' => $execUserId]);
    $target = (int)($targetStmt->fetchColumn() ?: 5);

    sendJsonResponse([
        'status' => 'success',
        'message' => "Meeting $newStatus successfully.",
        'data' => [
            'meeting_id' => $meetingId,
            'client_name' => $meeting['client_name'],
            'status' => $newStatus,
            'reviewed_by' => $callerId,
            'reviewer_name' => $caller['full_name'],
            'executive_user_id' => $execUserId,
            'date' => $today,
            'approved_meetings_today' => $approvedCount,
            'daily_target' => $target,
            'target_achieved' => $approvedCount >= $target,
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('review meeting error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to update meeting status: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
