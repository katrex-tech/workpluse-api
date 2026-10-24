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

// Strictly protected for HR and Super Admin
$caller = requireRole(['super_admin', 'hr']);

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid JSON payload.',
    ], 400);
}

$type = trim($input['type'] ?? ''); // 'attendance_missing', 'leave', 'work_report', 'shift_change', 'face_change'
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim($input['action'] ?? '')); // 'approve' or 'reject'
$remarks = trim($input['remarks'] ?? '');

$allowedTypes = ['attendance_missing', 'leave', 'work_report', 'shift_change', 'face_change'];
if (!in_array($type, $allowedTypes, true)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid request type: ' . $type,
    ], 422);
}

if (!$id) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request ID is required.',
    ], 422);
}

if ($action !== 'approve' && $action !== 'reject') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Action must be approve or reject.',
    ], 422);
}

$statusValue = ($action === 'approve') ? 'approved' : 'rejected';
$reviewerId = (int) $caller['id'];
$reviewerName = $caller['full_name'] ?? 'HR / Admin';

try {
    $targetUserId = null;
    $notifTitle = '';
    $notifMessage = '';

    if ($type === 'shift_change') {
        $stmt = $pdo->prepare("SELECT * FROM shift_change_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Shift change request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE shift_change_requests
            SET status = :status, admin_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            // Apply new shift to user!
            $userShiftUpd = $pdo->prepare("UPDATE users SET shift_id = :shift_id WHERE id = :user_id");
            $userShiftUpd->execute([
                'shift_id' => $req['requested_shift_id'],
                'user_id' => $targetUserId,
            ]);

            $shiftName = $pdo->query("SELECT name FROM shifts WHERE id = " . (int)$req['requested_shift_id'])->fetchColumn();
            $notifTitle = "Shift Change Approved! 🎉";
            $notifMessage = "Your request to switch to '$shiftName' has been approved by $reviewerName.";
        } else {
            $notifTitle = "Shift Change Request Rejected";
            $notifMessage = "Your shift change request was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'face_change') {
        $stmt = $pdo->prepare("SELECT * FROM face_change_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Face change request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE face_change_requests
            SET status = :status, admin_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            // Reset face biometrics for user so they can register new face!
            $resetFace = $pdo->prepare("
                UPDATE users
                SET face_registered = 0, face_data = NULL, face_registered_at = NULL
                WHERE id = :user_id
            ");
            $resetFace->execute(['user_id' => $targetUserId]);

            $notifTitle = "Face Re-Registration Approved! 📸";
            $notifMessage = "Your face biometrics have been reset by $reviewerName. You can now scan and register your new face in Face ID Management.";
        } else {
            $notifTitle = "Face Change Request Rejected";
            $notifMessage = "Your face change request was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'attendance_missing') {
        $stmt = $pdo->prepare("SELECT * FROM attendance_regularization_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Attendance regularization request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE attendance_regularization_requests
            SET status = :status, admin_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            // Insert or update attendance for that date
            $inDateTime = $req['date'] . ' ' . ($req['check_in_time'] ?: '10:00:00');
            $outDateTime = $req['date'] . ' ' . ($req['check_out_time'] ?: '19:00:00');
            $hrs = round((strtotime($outDateTime) - strtotime($inDateTime)) / 3600, 2);

            $attCheck = $pdo->prepare("SELECT id FROM attendance WHERE user_id = :uid AND date = :date LIMIT 1");
            $attCheck->execute(['uid' => $targetUserId, 'date' => $req['date']]);
            $attId = $attCheck->fetchColumn();

            if ($attId) {
                $attUpd = $pdo->prepare("
                    UPDATE attendance
                    SET punch_in = :in, punch_out = :out, working_hours = :hrs, status = 'approved'
                    WHERE id = :id
                ");
                $attUpd->execute(['in' => $inDateTime, 'out' => $outDateTime, 'hrs' => $hrs, 'id' => $attId]);
            } else {
                $attIns = $pdo->prepare("
                    INSERT INTO attendance (user_id, date, punch_in, punch_out, working_hours, status)
                    VALUES (:uid, :date, :in, :out, :hrs, 'approved')
                ");
                $attIns->execute(['uid' => $targetUserId, 'date' => $req['date'], 'in' => $inDateTime, 'out' => $outDateTime, 'hrs' => $hrs]);
            }

            $notifTitle = "Missing Attendance Approved! ⏱️";
            $notifMessage = "Your attendance regularization for " . $req['date'] . " has been approved by $reviewerName.";
        } else {
            $notifTitle = "Missing Attendance Request Rejected";
            $notifMessage = "Your attendance regularization for " . $req['date'] . " was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'leave') {
        $stmt = $pdo->prepare("SELECT * FROM leaves WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Leave request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("UPDATE leaves SET status = :status WHERE id = :id");
        $upd->execute(['status' => $statusValue, 'id' => $id]);

        if ($action === 'approve') {
            $notifTitle = "Leave Request Approved! 🌴";
            $notifMessage = "Your {$req['leave_type']} from {$req['from_date']} to {$req['to_date']} has been approved by $reviewerName.";
        } else {
            $notifTitle = "Leave Request Rejected";
            $notifMessage = "Your {$req['leave_type']} request was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'work_report') {
        $stmt = $pdo->prepare("SELECT * FROM work_reports WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Work report not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("UPDATE work_reports SET status = :status WHERE id = :id");
        $upd->execute(['status' => $statusValue, 'id' => $id]);

        if ($action === 'approve') {
            $notifTitle = "Work Report Approved! 📋";
            $notifMessage = "Your work report for {$req['report_date']} has been approved by $reviewerName.";
        } else {
            $notifTitle = "Work Report Rejected / Feedback";
            $notifMessage = "Your work report for {$req['report_date']} was reviewed by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    }

    // Insert notification for the employee
    if ($targetUserId && $notifTitle !== '') {
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notifStmt->execute([$targetUserId, $notifTitle, $notifMessage]);
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => "Request has been $statusValue successfully.",
        'data' => [
            'id' => $id,
            'type' => $type,
            'status' => $statusValue,
            'reviewed_by' => $reviewerName,
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Action request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to process request: ' . $e->getMessage(),
    ], 500);
}
