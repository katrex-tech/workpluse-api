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

$userIdInput = $input['user_id'] ?? null;
$userId = is_string($userIdInput) || is_int($userIdInput)
    ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$reportDate = $input['report_date'] ?? null;
$description = isset($input['description']) && is_string($input['description'])
    ? trim($input['description'])
    : '';

$projectName = isset($input['project_name']) && is_string($input['project_name'])
    ? trim($input['project_name'])
    : null;

$managerId = isset($input['manager_id']) ? filter_var($input['manager_id'], FILTER_VALIDATE_INT) : null;
$managerName = isset($input['manager_name']) && is_string($input['manager_name'])
    ? trim($input['manager_name'])
    : null;

$totalTime = isset($input['total_time']) && is_string($input['total_time'])
    ? trim($input['total_time'])
    : null;

$isValidDate = static function ($value) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($userId === false || !$isValidDate($reportDate) || $description === '') {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid user_id, report_date (YYYY-MM-DD), and non-empty description are required.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id, full_name, employee_id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $userId]);
    $user = $userQuery->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    // Check available columns in work_reports to prevent SQL 1054 errors on unmigrated databases
    $existingCols = [];
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM work_reports");
        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $existingCols = ['id', 'user_id', 'report_date', 'description', 'status', 'created_at'];
    }

    $hasCol = static function ($col) use ($existingCols) {
        return in_array($col, $existingCols, true);
    };

    if ($hasCol('project_name') && $hasCol('manager_id') && $hasCol('manager_name') && $hasCol('total_time')) {
        $insert = $pdo->prepare(
            'INSERT INTO work_reports (user_id, project_name, manager_id, manager_name, report_date, description, total_time, status)
             VALUES (:user_id, :project_name, :manager_id, :manager_name, :report_date, :description, :total_time, :status)'
        );
        $insert->execute([
            'user_id' => $userId,
            'project_name' => $projectName,
            'manager_id' => $managerId ?: null,
            'manager_name' => $managerName ?: null,
            'report_date' => $reportDate,
            'description' => $description,
            'total_time' => $totalTime,
            'status' => 'submitted',
        ]);
    } else {
        // Fallback for legacy work_reports schema: preserve project, manager, and time in description
        $prefixItems = [];
        if (!empty($projectName)) {
            $prefixItems[] = "Project: {$projectName}";
        }
        if (!empty($managerName)) {
            $prefixItems[] = "Manager: {$managerName}";
        }
        if (!empty($totalTime)) {
            $prefixItems[] = "Total Time: {$totalTime}";
        }
        $legacyDescription = !empty($prefixItems)
            ? '[' . implode(' | ', $prefixItems) . "]\n\n" . $description
            : $description;

        $insert = $pdo->prepare(
            'INSERT INTO work_reports (user_id, report_date, description, status)
             VALUES (:user_id, :report_date, :description, :status)'
        );
        $insert->execute([
            'user_id' => $userId,
            'report_date' => $reportDate,
            'description' => $legacyDescription,
            'status' => 'submitted',
        ]);
    }
    $reportId = (int) $pdo->lastInsertId();

    // Create notifications for HRs & Super Admins and selected manager
    try {
        $empName = $user['full_name'] ?? 'An employee';
        $adminsStmt = $pdo->query("SELECT id FROM users WHERE role IN ('super_admin', 'admin', 'hr') AND status = 'active'");
        $adminIds = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($managerId && !in_array($managerId, $adminIds, false)) {
            $adminIds[] = (int) $managerId;
        }

        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $notifTitle = "Daily Work Report Submitted 📋";
        $projectContext = $projectName ? " for '{$projectName}'" : "";
        $notifMsg = "{$empName} submitted a work report{$projectContext} for {$reportDate}.";
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([(int)$adminId, $notifTitle, $notifMsg]);
        }

        require_once __DIR__ . '/../../config/fcm_helper.php';
        sendFcmToUsers($pdo, $adminIds, $notifTitle, $notifMsg, ['type' => 'work_report', 'report_id' => (string)$reportId]);
    } catch (Exception $e) {
        error_log('Failed to create work report notification: ' . $e->getMessage());
    }

} catch (PDOException $exception) {
    error_log('Work report submission failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to submit work report: ' . $exception->getMessage(),
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Work report submitted successfully.',
    'data' => [
        'id' => $reportId,
    ],
], 201);
