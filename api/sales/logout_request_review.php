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
        'message' => 'Method not allowed. POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

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

$requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
$action = strtolower(trim($input['action'] ?? ''));
$remarks = trim($input['review_remarks'] ?? ($input['remarks'] ?? ''));

if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Valid request_id and action (approve / reject) are required.',
        'data' => null,
    ], 422);
}

$newStatus = ($action === 'approve') ? 'approved' : 'rejected';

try {
    $checkStmt = $pdo->prepare('SELECT id, user_id, request_date, status FROM sales_logout_requests WHERE id = :id LIMIT 1');
    $checkStmt->execute(['id' => $requestId]);
    $req = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Logout request not found.',
            'data' => null,
        ], 404);
    }

    $updateStmt = $pdo->prepare('
        UPDATE sales_logout_requests
        SET status = :st,
            reviewed_by = :reviewer,
            review_remarks = :remarks,
            reviewed_at = NOW()
        WHERE id = :id
    ');
    $updateStmt->execute([
        'st' => $newStatus,
        'reviewer' => $callerId,
        'remarks' => $remarks ?: ($action === 'approve' ? 'Early logout approved by Administrator.' : 'Early logout rejected.'),
        'id' => $requestId,
    ]);

    sendJsonResponse([
        'status' => 'success',
        'message' => "Logout request has been $newStatus.",
        'data' => [
            'request_id' => $requestId,
            'status' => $newStatus,
            'user_id' => (int)$req['user_id'],
            'reviewed_by' => $callerId,
            'reviewer_name' => $caller['full_name'],
            'reviewed_at' => date('Y-m-d H:i:s'),
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('review logout_request error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to review logout request: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
