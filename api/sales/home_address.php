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
require_once __DIR__ . '/../../config/fcm_helper.php';

$currentUser = getAuthenticatedUser();
$userId = (int) $currentUser['id'];
$role = strtolower(trim($currentUser['role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'super_admin', 'sales_head', 'hr', 'tl'], true);

// ==========================================
// HANDLE GET
// ==========================================
if ($method === 'GET') {
    try {
        // If caller requested list (for admin/hr)
        $isList = isset($_GET['list']) && $_GET['list'] == '1';

        if ($isList && $isAdmin) {
            $statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
            $where = [];
            $params = [];

            if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
                $where[] = 'har.status = :status';
                $params['status'] = $statusFilter;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $stmt = $pdo->prepare("
                SELECT har.id, har.user_id, u.full_name as employee_name, u.employee_id, u.profile_photo,
                       u.department, u.designation, har.latitude, har.longitude, har.address,
                       har.reason, har.status, har.review_remarks, har.reviewed_at, har.created_at,
                       rev.full_name as reviewer_name
                FROM home_address_requests har
                JOIN users u ON har.user_id = u.id
                LEFT JOIN users rev ON har.reviewed_by = rev.id
                $whereClause
                ORDER BY har.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($rows as $row) {
                $results[] = [
                    'id' => (int) $row['id'],
                    'user_id' => (int) $row['user_id'],
                    'employee_name' => $row['employee_name'] ?? 'Executive',
                    'employee_id' => $row['employee_id'] ?? '',
                    'profile_photo' => formatMediaUrl($row['profile_photo'] ?? null),
                    'department' => $row['department'] ?? 'Sales',
                    'designation' => $row['designation'] ?? 'Sales Executive',
                    'latitude' => (float) $row['latitude'],
                    'longitude' => (float) $row['longitude'],
                    'address' => $row['address'],
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
                'message' => 'Home address requests retrieved.',
                'data' => [
                    'requests' => $results,
                    'count' => count($results),
                ],
            ], 200);
        }

        // Return current user's home address and active request status
        $uStmt = $pdo->prepare('
            SELECT id, full_name, is_field_sales, base_lat, base_lng, base_address
            FROM users
            WHERE id = :uid
            LIMIT 1
        ');
        $uStmt->execute(['uid' => $userId]);
        $uData = $uStmt->fetch(PDO::FETCH_ASSOC);

        $hasRegisteredHome = !empty($uData['base_lat']) && !empty($uData['base_lng']);

        // Check latest request
        $rStmt = $pdo->prepare('
            SELECT id, latitude, longitude, address, reason, status, review_remarks, reviewed_at, created_at
            FROM home_address_requests
            WHERE user_id = :uid
            ORDER BY id DESC
            LIMIT 1
        ');
        $rStmt->execute(['uid' => $userId]);
        $latestReq = $rStmt->fetch(PDO::FETCH_ASSOC);

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Home address details retrieved.',
            'data' => [
                'user_id' => $userId,
                'has_registered_home' => $hasRegisteredHome,
                'registered_home' => $hasRegisteredHome ? [
                    'latitude' => (float) $uData['base_lat'],
                    'longitude' => (float) $uData['base_lng'],
                    'address' => $uData['base_address'] ?? 'Home Address',
                ] : null,
                'latest_request' => $latestReq ? [
                    'id' => (int) $latestReq['id'],
                    'latitude' => (float) $latestReq['latitude'],
                    'longitude' => (float) $latestReq['longitude'],
                    'address' => $latestReq['address'],
                    'reason' => $latestReq['reason'],
                    'status' => $latestReq['status'],
                    'review_remarks' => $latestReq['review_remarks'],
                    'created_at' => $latestReq['created_at'],
                ] : null,
            ],
        ], 200);

    } catch (PDOException $e) {
        error_log('home_address GET error: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to fetch home address: ' . $e->getMessage(),
            'data' => null,
        ], 500);
    }
}

// ==========================================
// HANDLE POST: SUBMIT HOME ADDRESS REQUEST
// ==========================================
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $input = [];
    if (!empty($rawBody)) {
        try {
            $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $input = $_POST;
        }
    } else {
        $input = $_POST;
    }

    $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
    $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;
    $address = trim((string) ($input['address'] ?? ''));
    $reason = trim((string) ($input['reason'] ?? 'Primary Home Base for Field Sales'));

    if ($latitude === null || $longitude === null) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Valid GPS latitude and longitude are required.',
            'data' => null,
        ], 422);
    }

    if ($address === '') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Home address description / street address is required.',
            'data' => null,
        ], 422);
    }

    try {
        // Check if there is already a pending request
        $chkStmt = $pdo->prepare("
            SELECT id FROM home_address_requests
            WHERE user_id = :uid AND status = 'pending'
            LIMIT 1
        ");
        $chkStmt->execute(['uid' => $userId]);
        $existingPending = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingPending) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'You already have a pending home address approval request. Please wait for Admin/HR review.',
                'data' => [
                    'request_id' => (int) $existingPending['id'],
                ],
            ], 409);
        }

        $insStmt = $pdo->prepare('
            INSERT INTO home_address_requests (user_id, latitude, longitude, address, reason, status, created_at)
            VALUES (:uid, :lat, :lng, :addr, :reason, "pending", NOW())
        ');
        $insStmt->execute([
            'uid' => $userId,
            'lat' => $latitude,
            'lng' => $longitude,
            'addr' => $address,
            'reason' => $reason,
        ]);

        $requestId = (int) $pdo->lastInsertId();

        // Send notifications & FCM to HR, Super Admins, and Reporting Manager
        try {
            $empName = $currentUser['full_name'] ?? 'Sales Executive';
            $empId = $currentUser['employee_id'] ?? "ID:$userId";

            $notifTitle = "🏠 Home Address Approval Request";
            $notifBody = "$empName ($empId) requested registration of their field sales home address: $address";

            // Find recipients: super_admin, hr, and reporting manager
            $recipientsQuery = "SELECT id FROM users WHERE (role IN ('super_admin', 'hr') OR id = (SELECT reporting_manager_id FROM users WHERE id = :uid)) AND status = 'active'";
            $recStmt = $pdo->prepare($recipientsQuery);
            $recStmt->execute(['uid' => $userId]);
            $recipientIds = array_unique(array_filter($recStmt->fetchAll(PDO::FETCH_COLUMN)));

            if (!empty($recipientIds)) {
                $notifInsert = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, is_read, created_at)
                    VALUES (?, ?, ?, 0, NOW())
                ");
                foreach ($recipientIds as $recId) {
                    $notifInsert->execute([(int) $recId, $notifTitle, $notifBody]);
                }

                sendFcmToUsers($pdo, $recipientIds, $notifTitle, $notifBody, [
                    'type' => 'home_address_request',
                    'request_id' => (string) $requestId,
                    'user_id' => (string) $userId,
                ]);
            }
        } catch (Exception $e) {
            error_log('Failed to dispatch home address request notifications: ' . $e->getMessage());
        }

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Home address registration request submitted. It will be verified and approved by Admin/HR.',
            'data' => [
                'id' => $requestId,
                'user_id' => $userId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $address,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ], 201);

    } catch (PDOException $e) {
        error_log('home_address POST error: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Failed to submit home address request: ' . $e->getMessage(),
            'data' => null,
        ], 500);
    }
}
