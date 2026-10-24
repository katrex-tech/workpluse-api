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

$userId = getAuthenticatedUserId(true);

try {
    // 1. Get user's assigned shift_id
    $userStmt = $pdo->prepare("SELECT id, full_name, role, shift_id FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    $shiftId = (int) ($user['shift_id'] ?? 1);
    if ($shiftId <= 0) $shiftId = 1;

    // 2. Fetch shift details
    $shiftStmt = $pdo->prepare("SELECT * FROM shifts WHERE id = :id LIMIT 1");
    $shiftStmt->execute(['id' => $shiftId]);
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        // Fallback to shift ID 1
        $shiftStmt = $pdo->query("SELECT * FROM shifts ORDER BY id ASC LIMIT 1");
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    }

    $startTs = strtotime($shift['start_time']);
    $endTs = strtotime($shift['end_time']);
    $lunchStartTs = strtotime($shift['lunch_start']);
    $lunchEndTs = strtotime($shift['lunch_end']);

    $startFormatted = date('h:i A', $startTs);
    $endFormatted = date('h:i A', $endTs);
    $lunchStartFormatted = date('h:i A', $lunchStartTs);
    $lunchEndFormatted = date('h:i A', $lunchEndTs);
    $lunchDurationMinutes = round(($lunchEndTs - $lunchStartTs) / 60);
    $totalHours = round(($endTs - $startTs) / 3600, 1);

    // 3. Fetch all available shifts (for request shift change modal)
    $allShiftsStmt = $pdo->query("SELECT id, name, start_time, end_time, lunch_start, lunch_end, personal_break_minutes, description FROM shifts WHERE is_active = 1 ORDER BY id ASC");
    $allShiftsRaw = $allShiftsStmt->fetchAll(PDO::FETCH_ASSOC);

    $availableShifts = [];
    foreach ($allShiftsRaw as $s) {
        $sStart = date('h:i A', strtotime($s['start_time']));
        $sEnd = date('h:i A', strtotime($s['end_time']));
        $availableShifts[] = [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'start_time' => $sStart,
            'end_time' => $sEnd,
            'time_range' => "$sStart - $sEnd",
            'lunch_start' => date('h:i A', strtotime($s['lunch_start'])),
            'lunch_end' => date('h:i A', strtotime($s['lunch_end'])),
            'personal_break_minutes' => (int) $s['personal_break_minutes'],
            'description' => $s['description'],
            'is_current' => ((int)$s['id'] === (int)$shift['id']),
        ];
    }

    // 4. Fetch pending shift change request (if any)
    $reqStmt = $pdo->prepare("
        SELECT scr.id, scr.current_shift_id, scr.requested_shift_id, scr.reason, scr.status, scr.created_at,
               s_req.name AS requested_shift_name, s_req.start_time AS req_start, s_req.end_time AS req_end
        FROM shift_change_requests scr
        INNER JOIN shifts s_req ON scr.requested_shift_id = s_req.id
        WHERE scr.user_id = :user_id
        ORDER BY scr.id DESC
        LIMIT 1
    ");
    $reqStmt->execute(['user_id' => $userId]);
    $pendingReq = $reqStmt->fetch(PDO::FETCH_ASSOC);

    $pendingRequestData = null;
    if ($pendingReq) {
        $reqStartFmt = date('h:i A', strtotime($pendingReq['req_start']));
        $reqEndFmt = date('h:i A', strtotime($pendingReq['req_end']));
        $pendingRequestData = [
            'id' => (int) $pendingReq['id'],
            'requested_shift_id' => (int) $pendingReq['requested_shift_id'],
            'requested_shift_name' => $pendingReq['requested_shift_name'],
            'requested_shift_time' => "$reqStartFmt - $reqEndFmt",
            'reason' => $pendingReq['reason'],
            'status' => $pendingReq['status'],
            'requested_at' => date('D, M d, Y - h:i A', strtotime($pendingReq['created_at'])),
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'My Shift details retrieved successfully.',
        'data' => [
            'shift' => [
                'id' => (int) $shift['id'],
                'name' => $shift['name'],
                'start_time' => $startFormatted,
                'end_time' => $endFormatted,
                'time_range' => "$startFormatted - $endFormatted",
                'lunch_start' => $lunchStartFormatted,
                'lunch_end' => $lunchEndFormatted,
                'lunch_time' => "$lunchStartFormatted - $lunchEndFormatted",
                'lunch_duration_minutes' => $lunchDurationMinutes,
                'personal_break_minutes' => (int) $shift['personal_break_minutes'],
                'total_hours' => "$totalHours Hours",
                'description' => $shift['description'],
                'working_days' => 'Monday - Saturday',
            ],
            'reminders' => [
                'punch_in_reminder' => "Shift starts at $startFormatted. Please punch in on time.",
                'punch_out_reminder' => "Shift ends at $endFormatted. Don't forget to punch out.",
                'lunch_reminder' => "Lunch break window: $lunchStartFormatted to $lunchEndFormatted.",
            ],
            'pending_change_request' => $pendingRequestData,
            'available_shifts' => $availableShifts,
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('My shift error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load shift details: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
