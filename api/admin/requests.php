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

// Strictly protected for HR and Super Admin
$caller = requireRole(['super_admin', 'hr']);

$filterType = $_GET['type'] ?? 'all'; // all, attendance_missing, leave, work_report, shift_change, face_change
$status = strtolower(trim($_GET['status'] ?? 'pending')); // pending, approved, rejected, all

try {
    $result = [
        'summary' => [
            'total_pending' => 0,
            'attendance_missing_pending' => 0,
            'leave_pending' => 0,
            'work_report_pending' => 0,
            'shift_change_pending' => 0,
            'face_change_pending' => 0,
        ],
        'attendance_missing' => [],
        'leave' => [],
        'work_report' => [],
        'shift_change' => [],
        'face_change' => [],
    ];

    // Helper for initials
    $getInitials = function($name) {
        $parts = explode(' ', trim($name));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        } elseif (count($parts) === 1 && strlen($parts[0]) > 0) {
            return strtoupper(substr($parts[0], 0, 2));
        }
        return 'WP';
    };

    // 1. Shift Change Requests
    $shiftSql = "
        SELECT scr.*, u.full_name AS employee_name, u.employee_id, u.email, u.department, u.designation,
               s_curr.name AS current_shift_name, s_curr.start_time AS curr_start, s_curr.end_time AS curr_end,
               s_req.name AS requested_shift_name, s_req.start_time AS req_start, s_req.end_time AS req_end
        FROM shift_change_requests scr
        JOIN users u ON scr.user_id = u.id
        JOIN shifts s_curr ON scr.current_shift_id = s_curr.id
        JOIN shifts s_req ON scr.requested_shift_id = s_req.id
    ";
    if ($status !== 'all') {
        $shiftSql .= " WHERE scr.status = " . $pdo->quote($status);
    }
    $shiftSql .= " ORDER BY scr.id DESC";
    $shiftRows = $pdo->query($shiftSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($shiftRows as $r) {
        $currStart = date('h:i A', strtotime($r['curr_start']));
        $currEnd = date('h:i A', strtotime($r['curr_end']));
        $reqStart = date('h:i A', strtotime($r['req_start']));
        $reqEnd = date('h:i A', strtotime($r['req_end']));

        $result['shift_change'][] = [
            'id' => (int) $r['id'],
            'type' => 'shift_change',
            'user_id' => (int) $r['user_id'],
            'employee_name' => $r['employee_name'],
            'employee_id' => $r['employee_id'] ?? 'WP-' . $r['user_id'],
            'email' => $r['email'],
            'department' => $r['department'],
            'designation' => $r['designation'],
            'initials' => $getInitials($r['employee_name']),
            'current_shift_id' => (int) $r['current_shift_id'],
            'current_shift_name' => $r['current_shift_name'],
            'current_shift_time' => "$currStart - $currEnd",
            'requested_shift_id' => (int) $r['requested_shift_id'],
            'requested_shift_name' => $r['requested_shift_name'],
            'requested_shift_time' => "$reqStart - $reqEnd",
            'reason' => $r['reason'],
            'status' => $r['status'],
            'admin_remarks' => $r['admin_remarks'],
            'created_at' => date('D, M d, Y - h:i A', strtotime($r['created_at'])),
        ];
    }

    // 2. Attendance Missing Requests
    $attSql = "
        SELECT arr.*, u.full_name AS employee_name, u.employee_id, u.email, u.department, u.designation
        FROM attendance_regularization_requests arr
        JOIN users u ON arr.user_id = u.id
    ";
    if ($status !== 'all') {
        $attSql .= " WHERE arr.status = " . $pdo->quote($status);
    }
    $attSql .= " ORDER BY arr.id DESC";
    $attRows = $pdo->query($attSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($attRows as $r) {
        $inFmt = $r['check_in_time'] ? date('h:i A', strtotime($r['check_in_time'])) : '--:--';
        $outFmt = $r['check_out_time'] ? date('h:i A', strtotime($r['check_out_time'])) : '--:--';
        $result['attendance_missing'][] = [
            'id' => (int) $r['id'],
            'type' => 'attendance_missing',
            'user_id' => (int) $r['user_id'],
            'employee_name' => $r['employee_name'],
            'employee_id' => $r['employee_id'] ?? 'WP-' . $r['user_id'],
            'email' => $r['email'],
            'department' => $r['department'],
            'designation' => $r['designation'],
            'initials' => $getInitials($r['employee_name']),
            'date' => date('D, M d, Y', strtotime($r['date'])),
            'raw_date' => $r['date'],
            'check_in_time' => $inFmt,
            'check_out_time' => $outFmt,
            'reason' => $r['reason'],
            'status' => $r['status'],
            'admin_remarks' => $r['admin_remarks'],
            'created_at' => date('D, M d, Y - h:i A', strtotime($r['created_at'])),
        ];
    }

    // 3. Leave Requests
    $leaveSql = "
        SELECT l.*, u.full_name AS employee_name, u.employee_id, u.email, u.department, u.designation
        FROM leaves l
        JOIN users u ON l.user_id = u.id
    ";
    if ($status !== 'all') {
        $leaveSql .= " WHERE l.status = " . $pdo->quote($status);
    }
    $leaveSql .= " ORDER BY l.id DESC";
    $leaveRows = $pdo->query($leaveSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leaveRows as $r) {
        $result['leave'][] = [
            'id' => (int) $r['id'],
            'type' => 'leave',
            'user_id' => (int) $r['user_id'],
            'employee_name' => $r['employee_name'],
            'employee_id' => $r['employee_id'] ?? 'WP-' . $r['user_id'],
            'email' => $r['email'],
            'department' => $r['department'],
            'designation' => $r['designation'],
            'initials' => $getInitials($r['employee_name']),
            'leave_type' => $r['leave_type'],
            'from_date' => date('M d, Y', strtotime($r['from_date'])),
            'to_date' => date('M d, Y', strtotime($r['to_date'])),
            'date_range' => date('M d', strtotime($r['from_date'])) . ' - ' . date('M d, Y', strtotime($r['to_date'])),
            'half_day' => $r['half_day'],
            'reason' => $r['reason'],
            'status' => $r['status'],
            'applied_at' => date('D, M d, Y - h:i A', strtotime($r['applied_at'])),
        ];
    }

    // 4. Work Reports
    $wrSql = "
        SELECT wr.*, u.full_name AS employee_name, u.employee_id, u.email, u.department, u.designation
        FROM work_reports wr
        JOIN users u ON wr.user_id = u.id
    ";
    if ($status !== 'all') {
        $wrSql .= " WHERE wr.status = " . $pdo->quote($status);
    }
    $wrSql .= " ORDER BY wr.id DESC";
    $wrRows = $pdo->query($wrSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($wrRows as $r) {
        $result['work_report'][] = [
            'id' => (int) $r['id'],
            'type' => 'work_report',
            'user_id' => (int) $r['user_id'],
            'employee_name' => $r['employee_name'],
            'employee_id' => $r['employee_id'] ?? 'WP-' . $r['user_id'],
            'email' => $r['email'],
            'department' => $r['department'],
            'designation' => $r['designation'],
            'initials' => $getInitials($r['employee_name']),
            'report_date' => date('D, M d, Y', strtotime($r['report_date'])),
            'description' => $r['description'],
            'status' => $r['status'],
            'created_at' => date('D, M d, Y - h:i A', strtotime($r['created_at'])),
        ];
    }

    // 5. Face Change Requests
    $faceSql = "
        SELECT fcr.*, u.full_name AS employee_name, u.employee_id, u.email, u.department, u.designation, u.face_registered
        FROM face_change_requests fcr
        JOIN users u ON fcr.user_id = u.id
    ";
    if ($status !== 'all') {
        $faceSql .= " WHERE fcr.status = " . $pdo->quote($status);
    }
    $faceSql .= " ORDER BY fcr.id DESC";
    $faceRows = $pdo->query($faceSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($faceRows as $r) {
        $result['face_change'][] = [
            'id' => (int) $r['id'],
            'type' => 'face_change',
            'user_id' => (int) $r['user_id'],
            'employee_name' => $r['employee_name'],
            'employee_id' => $r['employee_id'] ?? 'WP-' . $r['user_id'],
            'email' => $r['email'],
            'department' => $r['department'],
            'designation' => $r['designation'],
            'initials' => $getInitials($r['employee_name']),
            'is_currently_registered' => (bool) $r['face_registered'],
            'reason' => $r['reason'],
            'status' => $r['status'],
            'admin_remarks' => $r['admin_remarks'],
            'created_at' => date('D, M d, Y - h:i A', strtotime($r['created_at'])),
        ];
    }

    // Pending counts
    $cntShift = (int) $pdo->query("SELECT COUNT(*) FROM shift_change_requests WHERE status = 'pending'")->fetchColumn();
    $cntAtt = (int) $pdo->query("SELECT COUNT(*) FROM attendance_regularization_requests WHERE status = 'pending'")->fetchColumn();
    $cntLeave = (int) $pdo->query("SELECT COUNT(*) FROM leaves WHERE status = 'pending'")->fetchColumn();
    $cntWr = (int) $pdo->query("SELECT COUNT(*) FROM work_reports WHERE status = 'pending' OR status = 'submitted'")->fetchColumn();
    $cntFace = (int) $pdo->query("SELECT COUNT(*) FROM face_change_requests WHERE status = 'pending'")->fetchColumn();

    $result['summary'] = [
        'total_pending' => ($cntShift + $cntAtt + $cntLeave + $cntWr + $cntFace),
        'attendance_missing_pending' => $cntAtt,
        'leave_pending' => $cntLeave,
        'work_report_pending' => $cntWr,
        'shift_change_pending' => $cntShift,
        'face_change_pending' => $cntFace,
    ];

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Admin requests retrieved successfully.',
        'data' => $result,
    ], 200);
} catch (PDOException $e) {
    error_log('Admin requests error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load admin requests: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
