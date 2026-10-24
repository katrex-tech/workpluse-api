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
if (!is_array($input)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid JSON payload.',
    ], 400);
}

$requestedShiftId = filter_var($input['requested_shift_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reason = trim($input['reason'] ?? '');

if (!$requestedShiftId) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please select a valid requested shift.',
    ], 422);
}

if ($reason === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide a reason for the shift change request.',
    ], 422);
}

try {
    // Current user's shift
    $currShiftId = (int) ($user['shift_id'] ?? 1);
    if ($currShiftId <= 0) $currShiftId = 1;

    if ($currShiftId === $requestedShiftId) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Requested shift is already your current assigned shift.',
        ], 422);
    }

    // Check target shift exists
    $targetShiftStmt = $pdo->prepare("SELECT id, name, start_time, end_time FROM shifts WHERE id = :id AND is_active = 1 LIMIT 1");
    $targetShiftStmt->execute(['id' => $requestedShiftId]);
    $targetShift = $targetShiftStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetShift) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'The selected shift does not exist or is inactive.',
        ], 404);
    }

    // Check if there is already a pending request
    $pendingStmt = $pdo->prepare("SELECT id FROM shift_change_requests WHERE user_id = :uid AND status = 'pending' LIMIT 1");
    $pendingStmt->execute(['uid' => $userId]);
    if ($pendingStmt->fetch()) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You already have a pending shift change request. Please wait for HR or Super Admin approval.',
        ], 409);
    }

    // Insert request
    $insertStmt = $pdo->prepare("
        INSERT INTO shift_change_requests (user_id, current_shift_id, requested_shift_id, reason, status)
        VALUES (:user_id, :curr_id, :req_id, :reason, 'pending')
    ");
    $insertStmt->execute([
        'user_id' => $userId,
        'curr_id' => $currShiftId,
        'req_id' => $requestedShiftId,
        'reason' => $reason,
    ]);
    $requestId = (int) $pdo->lastInsertId();

    // Create notifications for all Super Admins & HRs
    $adminsStmt = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'hr') AND status = 'active'");
    $adminIds = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

    $notifStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");

    $empName = $user['full_name'] ?? 'An employee';
    $shiftName = $targetShift['name'];
    foreach ($adminIds as $adminId) {
        $notifStmt->execute([
            $adminId,
            'New Shift Change Request',
            "$empName requested a shift change to $shiftName. Reason: $reason"
        ]);
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Shift change request submitted successfully. It will be reviewed by HR/Super Admin.',
        'data' => [
            'id' => $requestId,
            'user_id' => $userId,
            'requested_shift_id' => $requestedShiftId,
            'requested_shift_name' => $targetShift['name'],
            'reason' => $reason,
            'status' => 'pending',
        ],
    ], 201);
} catch (PDOException $e) {
    error_log('Shift change request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to submit shift change request: ' . $e->getMessage(),
    ], 500);
}
