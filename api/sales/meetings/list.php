<?php

require_once __DIR__ . '/../../../config/config.php';

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

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth_check.php';

$currentUser = getAuthenticatedUser();
$currentUserId = (int)$currentUser['id'];
$role = strtolower(trim($currentUser['role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'super_admin', 'sales_head', 'hr'], true);

$targetUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUserId;
if ($targetUserId !== $currentUserId && !$isAdmin) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Forbidden: You can only view your own meetings.',
        'data' => null,
    ], 403);
}

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
$statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';

try {
    $where = [];
    $params = [];

    if (!$isAdmin || (isset($_GET['user_id']) && is_numeric($_GET['user_id']))) {
        $where[] = 'm.user_id = :uid';
        $params['uid'] = $targetUserId;
    }

    if ($date !== null) {
        $where[] = 'DATE(m.check_in_time) = :dt';
        $params['dt'] = $date;
    }

    if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'm.status = :status';
        $params['status'] = $statusFilter;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT m.id, m.user_id, u.full_name as executive_name, u.employee_id,
               m.client_name, m.client_phone, m.meeting_purpose, m.notes,
               m.photo_path, m.watermarked_photo_path, m.latitude, m.longitude,
               m.location_address, m.check_in_time, m.status,
               m.reviewed_by, rev.full_name as reviewer_name, m.review_remarks, m.reviewed_at,
               m.created_at
        FROM sales_meetings m
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN users rev ON m.reviewed_by = rev.id
        $whereClause
        ORDER BY m.check_in_time DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $meetings = [];
    $approvedCount = 0;
    $pendingCount = 0;
    $rejectedCount = 0;

    foreach ($rows as $r) {
        if ($r['status'] === 'approved') $approvedCount++;
        elseif ($r['status'] === 'pending') $pendingCount++;
        elseif ($r['status'] === 'rejected') $rejectedCount++;

        $photoRel = $r['watermarked_photo_path'] ?: $r['photo_path'];

        $meetings[] = [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'executive_name' => $r['executive_name'] ?? 'Executive',
            'employee_id' => $r['employee_id'] ?? '',
            'client_name' => $r['client_name'],
            'client_phone' => $r['client_phone'],
            'meeting_purpose' => $r['meeting_purpose'],
            'notes' => $r['notes'],
            'photo_url' => formatMediaUrl($photoRel),
            'latitude' => (float)$r['latitude'],
            'longitude' => (float)$r['longitude'],
            'location_address' => $r['location_address'],
            'check_in_time' => $r['check_in_time'],
            'formatted_time' => date('h:i A', strtotime($r['check_in_time'])),
            'formatted_date' => date('d M Y', strtotime($r['check_in_time'])),
            'status' => $r['status'],
            'reviewed_by' => $r['reviewed_by'] ? (int)$r['reviewed_by'] : null,
            'reviewer_name' => $r['reviewer_name'] ?? null,
            'review_remarks' => $r['review_remarks'] ?? null,
            'reviewed_at' => $r['reviewed_at'],
        ];
    }

    // Fetch user's target
    $target = 5;
    $userStmt = $pdo->prepare('SELECT daily_meeting_target FROM users WHERE id = :uid LIMIT 1');
    $userStmt->execute(['uid' => $targetUserId]);
    $uData = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($uData && !empty($uData['daily_meeting_target'])) {
        $target = (int)$uData['daily_meeting_target'];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Meetings retrieved successfully.',
        'data' => [
            'summary' => [
                'total_meetings' => count($meetings),
                'approved_meetings' => $approvedCount,
                'pending_meetings' => $pendingCount,
                'rejected_meetings' => $rejectedCount,
                'daily_meeting_target' => $target,
                'target_achieved' => $approvedCount >= $target,
            ],
            'meetings' => $meetings,
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('list meetings error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load meetings: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
