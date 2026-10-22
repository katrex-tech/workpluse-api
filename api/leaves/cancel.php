<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'PUT' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: PUT, POST, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be valid JSON.',
        'data' => [],
    ], 400);
}

if (!is_array($input)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must be a JSON object.',
        'data' => [],
    ], 400);
}

$applicationId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($applicationId === false || $userId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid id and user_id are required.',
        'data' => [],
    ], 422);
}

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare(
        'SELECT id, user_id, leave_type, from_date, to_date, half_day, status
         FROM leaves
         WHERE id = :id AND user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $query->execute([
        'id' => $applicationId,
        'user_id' => $userId,
    ]);
    $application = $query->fetch();

    if ($application === false) {
        $pdo->rollBack();
        sendJsonResponse([
            'status' => false,
            'message' => 'Leave application not found for this user.',
            'data' => [],
        ], 404);
    }

    if ($application['status'] !== 'pending') {
        $pdo->rollBack();
        sendJsonResponse([
            'status' => false,
            'message' => 'Only pending leave applications can be cancelled.',
            'data' => [],
        ], 409);
    }

    // Set to rejected or cancelled (status enum is 'pending','approved','rejected')
    $update = $pdo->prepare(
        'UPDATE leaves
         SET status = :new_status
         WHERE id = :id AND user_id = :user_id AND status = :current_status'
    );
    $update->execute([
        'new_status' => 'rejected',
        'id' => $applicationId,
        'user_id' => $userId,
        'current_status' => 'pending',
    ]);

    // Restore leave balance
    $d1 = new DateTime($application['from_date']);
    $d2 = new DateTime($application['to_date']);
    $days = $d1->diff($d2)->days + 1;
    if ($application['half_day'] !== 'none') {
        $days = 0.5;
    }
    $year = date('Y', strtotime($application['from_date']));

    $restore = $pdo->prepare(
        'UPDATE leave_balance
         SET used = GREATEST(0, used - :days_used), remaining = remaining + :days_rem
         WHERE user_id = :user_id AND leave_type = :leave_type AND year = :year'
    );
    $restore->execute([
        'days_used' => $days,
        'days_rem' => $days,
        'user_id' => $userId,
        'leave_type' => $application['leave_type'],
        'year' => $year,
    ]);

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Leave cancellation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to cancel leave application.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Leave application cancelled successfully.',
    'data' => [
        'id' => $applicationId,
        'user_id' => $userId,
        'status' => 'cancelled',
    ],
]);
