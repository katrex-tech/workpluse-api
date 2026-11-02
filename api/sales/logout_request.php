<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. GET or POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$currentUser = getAuthenticatedUser();
$userId = (int)$currentUser['id'];
$role = strtolower(trim($currentUser['role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'super_admin', 'sales_head', 'hr'], true);

// ==========================================
// HANDLE GET: LIST LOGOUT REQUESTS
// ==========================================
if ($method === 'GET') {
    try {
        $statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
        $dateFilter = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;

        $where = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = 'r.user_id = :uid';
            $params['uid'] = $userId;
        }

        if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $statusFilter;
        }

        if ($dateFilter !== null) {
            $where[] = 'r.request_date = :dt';
            $params['dt'] = $dateFilter;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT r.id, r.user_id, u.full_name as executive_name, u.employee_id, u.profile_photo,
                   r.request_date, r.target_meetings, r.completed_meetings, r.approved_meetings,
                   r.reason, r.status, r.review_remarks, r.reviewed_at, r.created_at,
                   rev.full_name as reviewer_name
            FROM sales_logout_requests r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users rev ON r.reviewed_by = rev.id
            $whereClause
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'executive_name' => $row['executive_name'] ?? 'Executive',
                'employee_id' => $row['employee_id'] ?? '',
                'profile_photo' => formatMediaUrl($row['profile_photo'] ?? null),
                'request_date' => $row['request_date'],
                'target_meetings' => (int)$row['target_meetings'],
                'completed_meetings' => (int)$row['completed_meetings'],
                'approved_meetings' => (int)$row['approved_meetings'],
                'reason' => $row['reason'],
                'status' => $row['status'],
                'review_remarks' => $row['review_remarks'],
                'reviewed_at' => $row['reviewed_at'],
                'reviewer_name' => $row['reviewer_name'],
                'created_at' => $row['created_at'],
            ];
        }

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Logout requests retrieved successfully.',
            'data' => [
                'requests' => $results,
                'count' => count($results),
            ],
        ], 200);

    } catch (PDOException $e) {
        error_log('get logout_requests error: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to load logout requests: ' . $e->getMessage(),
            'data' => null,
        ], 500);
    }
}

// ==========================================
// HANDLE POST: SUBMIT EARLY LOGOUT REQUEST
// ==========================================
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

$reason = trim($input['reason'] ?? '');
if ($reason === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Please provide a valid reason for early logout.',
        'data' => null,
    ], 422);
}

$today = date('Y-m-d');

try {
    // 1. Fetch current target and stats (Static 3 daily target)
    $target = 3;

    $mStmt = $pdo->prepare('
        SELECT status, COUNT(*) as cnt 
        FROM sales_meetings 
        WHERE user_id = :uid AND DATE(check_in_time) = :dt 
        GROUP BY status
    ');
    $mStmt->execute(['uid' => $userId, 'dt' => $today]);
    $counts = $mStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $approved = (int)($counts['approved'] ?? 0);
    $total = (int)array_sum($counts);

    // 2. Check if a request already exists today
    $checkStmt = $pdo->prepare('
        SELECT id, status 
        FROM sales_logout_requests 
        WHERE user_id = :uid AND request_date = :dt 
        LIMIT 1
    ');
    $checkStmt->execute(['uid' => $userId, 'dt' => $today]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'approved') {
            sendJsonResponse([
                'status' => 'success',
                'message' => 'Early logout request is already approved by Admin.',
                'data' => [
                    'id' => (int)$existing['id'],
                    'status' => 'approved',
                ],
            ], 200);
        }

        // Update existing pending request
        $updateStmt = $pdo->prepare('
            UPDATE sales_logout_requests 
            SET reason = :reason, target_meetings = :target, completed_meetings = :completed, approved_meetings = :approved, status = "pending", created_at = NOW()
            WHERE id = :id
        ');
        $updateStmt->execute([
            'reason' => $reason,
            'target' => $target,
            'completed' => $total,
            'approved' => $approved,
            'id' => $existing['id'],
        ]);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Logout approval request updated and submitted to Admin.',
            'data' => [
                'id' => (int)$existing['id'],
                'status' => 'pending',
                'reason' => $reason,
            ],
        ], 200);
    }

    // 3. Insert new request
    $insertStmt = $pdo->prepare('
        INSERT INTO sales_logout_requests (
            user_id, request_date, target_meetings, completed_meetings, approved_meetings, reason, status, created_at
        ) VALUES (
            :uid, :dt, :target, :completed, :approved, :reason, "pending", NOW()
        )
    ');
    $insertStmt->execute([
        'uid' => $userId,
        'dt' => $today,
        'target' => $target,
        'completed' => $total,
        'approved' => $approved,
        'reason' => $reason,
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Send notifications to Admin, HR, and Team Lead
    try {
        require_once __DIR__ . '/../../config/fcm_helper.php';

        $uStmt = $pdo->prepare('SELECT full_name, employee_id FROM users WHERE id = :uid LIMIT 1');
        $uStmt->execute(['uid' => $userId]);
        $empInfo = $uStmt->fetch(PDO::FETCH_ASSOC);
        $empName = $empInfo['full_name'] ?? 'Sales Executive';
        $empId = $empInfo['employee_id'] ?? "ID:$userId";

        $notifTitle = "🚪 Punch-out Approval Request";
        $notifBody = "$empName ($empId) requested early punch-out: $reason (Approved: $approved/$target meetings).";

        $recStmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE (role IN ('super_admin', 'hr', 'sales_head', 'tl') OR id = (SELECT reporting_manager_id FROM users WHERE id = :uid)) 
              AND status = 'active'
        ");
        $recStmt->execute(['uid' => $userId]);
        $recIds = array_unique(array_filter($recStmt->fetchAll(PDO::FETCH_COLUMN)));

        if (!empty($recIds)) {
            $notifInsert = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            foreach ($recIds as $rId) {
                $notifInsert->execute([(int)$rId, $notifTitle, $notifBody]);
            }

            sendFcmToUsers($pdo, $recIds, $notifTitle, $notifBody, [
                'type' => 'punch_out',
                'request_id' => (string)$newId,
                'user_id' => (string)$userId,
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to dispatch logout request notification: ' . $e->getMessage());
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Early logout request submitted. Awaiting Admin approval.',
        'data' => [
            'id' => $newId,
            'user_id' => $userId,
            'request_date' => $today,
            'reason' => $reason,
            'target_meetings' => $target,
            'approved_meetings' => $approved,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ], 201);

} catch (PDOException $e) {
    error_log('submit logout_request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to submit logout request: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
