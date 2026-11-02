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

// Strictly protected for HR, Super Admin, and Team Leads / Sales Heads
$caller = requireRole(['super_admin', 'hr', 'tl', 'sales_head']);

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Invalid JSON payload.',
    ], 400);
}

$type = trim($input['type'] ?? ''); // 'attendance_missing', 'leave', 'work_report', 'shift_change', 'face_change', 'visit', 'home_address', 'punch_out'
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim($input['action'] ?? '')); // 'approve' or 'reject'
$remarks = trim($input['remarks'] ?? '');

$allowedTypes = [
    'attendance_missing',
    'leave',
    'work_report',
    'shift_change',
    'face_change',
    'visit',
    'home_address',
    'punch_out',
];

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
            $userReset = $pdo->prepare("
                UPDATE users
                SET face_registered = 0, face_data = NULL, compreface_image_id = NULL, compreface_subject = NULL,
                    face_registered_at = NULL, face_consent_given = 0
                WHERE id = :user_id
            ");
            $userReset->execute(['user_id' => $targetUserId]);

            $notifTitle = "Face Re-Registration Approved! 📸";
            $notifMessage = "Your facial biometric profile has been reset. Please register your new face in Face Management.";
        } else {
            $notifTitle = "Face Re-Registration Request Rejected";
            $notifMessage = "Your request to update face registration was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'attendance_missing') {
        $stmt = $pdo->prepare("SELECT * FROM attendance_regularization_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Regularization request not found.'], 404);
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
            $reqDate = $req['date'];
            $chkAtt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = :uid AND date = :dt LIMIT 1");
            $chkAtt->execute(['uid' => $targetUserId, 'dt' => $reqDate]);
            $existingAtt = $chkAtt->fetch(PDO::FETCH_ASSOC);

            if ($existingAtt) {
                $attUpd = $pdo->prepare("
                    UPDATE attendance 
                    SET punch_in = COALESCE(punch_in, :pi), punch_out = COALESCE(:po, punch_out), status = 'present'
                    WHERE id = :id
                ");
                $attUpd->execute([
                    'pi' => $req['check_in_time'] ? ($reqDate . ' ' . $req['check_in_time']) : null,
                    'po' => $req['check_out_time'] ? ($reqDate . ' ' . $req['check_out_time']) : null,
                    'id' => $existingAtt['id'],
                ]);
            } else {
                $attIns = $pdo->prepare("
                    INSERT INTO attendance (user_id, date, punch_in, punch_out, status, created_at)
                    VALUES (:uid, :dt, :pi, :po, 'present', NOW())
                ");
                $attIns->execute([
                    'uid' => $targetUserId,
                    'dt' => $reqDate,
                    'pi' => $req['check_in_time'] ? ($reqDate . ' ' . $req['check_in_time']) : ($reqDate . ' 09:30:00'),
                    'po' => $req['check_out_time'] ? ($reqDate . ' ' . $req['check_out_time']) : ($reqDate . ' 18:30:00'),
                ]);
            }

            $notifTitle = "Attendance Regularization Approved! ✅";
            $notifMessage = "Your attendance request for " . date('d M, Y', strtotime($reqDate)) . " has been approved.";
        } else {
            $notifTitle = "Attendance Regularization Rejected";
            $notifMessage = "Your attendance request for " . date('d M, Y', strtotime($req['date'])) . " was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'leave') {
        $stmt = $pdo->prepare("SELECT * FROM leaves WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Leave request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("UPDATE leaves SET status = :status, rejection_reason = :remarks WHERE id = :id");
        $upd->execute(['status' => $statusValue, 'remarks' => $remarks, 'id' => $id]);

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
        $upd = $pdo->prepare("UPDATE work_reports SET status = :status, admin_remarks = :remarks WHERE id = :id");
        $upd->execute(['status' => $statusValue, 'remarks' => $remarks, 'id' => $id]);

        if ($action === 'approve') {
            $notifTitle = "Work Report Approved! 📋";
            $notifMessage = "Your work report for {$req['report_date']} has been approved by $reviewerName.";
        } else {
            $notifTitle = "Work Report Rejected / Feedback";
            $notifMessage = "Your work report for {$req['report_date']} was reviewed by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'visit') {
        // 6. Field Sales Meeting Approval / Rejection
        $stmt = $pdo->prepare("SELECT * FROM sales_meetings WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Meeting visit record not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE sales_meetings
            SET status = :status, review_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            $notifTitle = "Meeting Visit Approved! 🎯";
            $notifMessage = "Your client visit with '{$req['client_name']}' has been approved and added to your daily target count.";
        } else {
            $notifTitle = "Meeting Visit Rejected ❌";
            $notifMessage = "Your client visit with '{$req['client_name']}' was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'home_address') {
        // 7. Home Address Registration Approval / Rejection
        $stmt = $pdo->prepare("SELECT * FROM home_address_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Home address request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE home_address_requests
            SET status = :status, review_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            // Apply approved home location to user
            $userUpd = $pdo->prepare("
                UPDATE users
                SET base_lat = :lat, base_lng = :lng, base_address = :addr
                WHERE id = :uid
            ");
            $userUpd->execute([
                'lat' => $req['latitude'],
                'lng' => $req['longitude'],
                'addr' => $req['address'],
                'uid' => $targetUserId,
            ]);

            $notifTitle = "Home Address Approved! 🏠";
            $notifMessage = "Your field sales registered home address ({$req['address']}) has been verified and approved by $reviewerName.";
        } else {
            $notifTitle = "Home Address Request Rejected ❌";
            $notifMessage = "Your home address registration request was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    } elseif ($type === 'punch_out') {
        // 8. Punch-out / Logout Approval Request
        $stmt = $pdo->prepare("SELECT * FROM sales_logout_requests WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            sendJsonResponse(['status' => 'error', 'message' => 'Punch-out approval request not found.'], 404);
        }

        $targetUserId = (int) $req['user_id'];
        $upd = $pdo->prepare("
            UPDATE sales_logout_requests
            SET status = :status, review_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'status' => $statusValue,
            'remarks' => $remarks,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        if ($action === 'approve') {
            $notifTitle = "Punch-Out Approval Granted! 🚪";
            $notifMessage = "Your punch-out exception request for {$req['request_date']} has been approved by $reviewerName. You may now punch out.";
        } else {
            $notifTitle = "Punch-Out Request Rejected ❌";
            $notifMessage = "Your punch-out exception request was rejected by $reviewerName." . ($remarks ? " Remarks: $remarks" : "");
        }
    }

    // Insert notification for the employee
    if ($targetUserId && $notifTitle !== '') {
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notifStmt->execute([$targetUserId, $notifTitle, $notifMessage]);

        require_once __DIR__ . '/../../config/fcm_helper.php';
        sendFcmToUsers($pdo, [$targetUserId], $notifTitle, $notifMessage, [
            'type' => $type,
            'id' => (string)$id,
            'status' => $statusValue,
        ]);
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
