<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
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

$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$leaveType = isset($input['leave_type']) && is_string($input['leave_type'])
    ? trim($input['leave_type'])
    : 'Casual Leave';
$fromDate = $input['from_date'] ?? null;
$toDate = $input['to_date'] ?? $fromDate;
$reason = isset($input['reason']) && is_string($input['reason'])
    ? trim($input['reason'])
    : '';

$halfDayInput = $input['half_day'] ?? 'none';
$halfDay = 'none';
if (is_bool($halfDayInput)) {
    $halfDay = $halfDayInput ? 'first' : 'none';
} elseif (in_array(strtolower((string)$halfDayInput), ['none', 'first', 'second'], true)) {
    $halfDay = strtolower((string)$halfDayInput);
}

$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (
    $userId === false
    || $leaveType === ''
    || !$isValidDate($fromDate)
    || !$isValidDate($toDate)
    || $reason === ''
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid user_id, leave_type, from_date, to_date, and reason are required.',
        'data' => [],
    ], 422);
}

if ($fromDate > $toDate) {
    sendJsonResponse([
        'status' => false,
        'message' => 'from_date must be on or before to_date.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :user_id LIMIT 1');
    $userQuery->execute(['user_id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO leaves
            (user_id, leave_type, from_date, to_date, half_day, reason, status)
         VALUES
            (:user_id, :leave_type, :from_date, :to_date, :half_day, :reason, :status)'
    );
    $insert->execute([
        'user_id' => $userId,
        'leave_type' => $leaveType,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'half_day' => $halfDay,
        'reason' => $reason,
        'status' => 'pending',
    ]);
    $leaveId = (int) $pdo->lastInsertId();

    // Calculate applied days
    $d1 = new DateTime($fromDate);
    $d2 = new DateTime($toDate);
    $days = $d1->diff($d2)->days + 1;
    if ($halfDay !== 'none') {
        $days = 0.5;
    }

    // Update leave balance if present
    $year = date('Y', strtotime($fromDate));
    $balanceUpdate = $pdo->prepare(
        'UPDATE leave_balance
         SET used = used + :days_used, remaining = GREATEST(0, remaining - :days_remaining)
         WHERE user_id = :user_id AND leave_type = :leave_type AND year = :year'
    );
    $balanceUpdate->execute([
        'days_used' => $days,
        'days_remaining' => $days,
        'user_id' => $userId,
        'leave_type' => $leaveType,
        'year' => $year,
    ]);

} catch (PDOException $exception) {
    error_log('Leave application creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to submit leave application: ' . $exception->getMessage(),
        'data' => [],
    ], 500);
}

    // Create notifications for HRs & Super Admins
    try {
        $userStmt = $pdo->prepare("SELECT full_name, employee_id FROM users WHERE id = :id LIMIT 1");
        $userStmt->execute(['id' => $userId]);
        $emp = $userStmt->fetch(PDO::FETCH_ASSOC);
        $empName = $emp ? $emp['full_name'] : 'An employee';

        $adminsStmt = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'hr') AND status = 'active'");
        $adminIds = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notifTitle = "New Leave Application 🏖️";
        $notifMsg = "{$empName} applied for {$leaveType} from {$fromDate} to {$toDate}.";
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([(int)$adminId, $notifTitle, $notifMsg]);
        }

        require_once __DIR__ . '/../../config/fcm_helper.php';
        sendFcmToUsers($pdo, $adminIds, $notifTitle, $notifMsg, ['type' => 'leave', 'leave_id' => (string)$leaveId]);
    } catch (Exception $e) {
        error_log('Failed to create leave notification: ' . $e->getMessage());
    }

sendJsonResponse([
    'status' => true,
    'message' => 'Leave application submitted successfully.',
    'data' => [
        'id' => $leaveId,
        'user_id' => $userId,
        'leave_type' => $leaveType,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'half_day' => $halfDay,
        'duration_days' => $days,
        'duration_formatted' => $days == 1 ? '1 Day' : ($days == 0.5 ? 'Half Day' : "$days Days"),
        'reason' => $reason,
        'status' => 'pending',
    ],
], 201);