<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$userIdInput = $_GET['user_id'] ?? null;
$userId = null;
if ($userIdInput !== null) {
    $userId = is_string($userIdInput) || is_int($userIdInput)
        ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ])
        : false;

    if ($userId === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'user_id must be a positive integer.',
            'data' => [],
        ], 422);
    }
}

$scope = strtolower(trim($_GET['scope'] ?? ''));
$filterUserId = isset($_GET['filter_user_id']) ? filter_var($_GET['filter_user_id'], FILTER_VALIDATE_INT) : null;

// Determine user role if user_id is provided
$userRole = '';
if ($userId !== null) {
    try {
        $uStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $uStmt->execute(['id' => $userId]);
        $row = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userRole = strtolower(trim($row['role'] ?? ''));
        }
    } catch (Exception $e) {}
}

$isAdminOrHr = in_array($userRole, ['admin', 'super_admin', 'hr']);

// Check which optional columns exist in work_reports to prevent SQL 1054 errors on unmigrated databases
$existingColumns = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM work_reports");
    $existingColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $existingColumns = ['id', 'user_id', 'report_date', 'description', 'status', 'created_at'];
}

$hasCol = static function ($col) use ($existingColumns) {
    return in_array($col, $existingColumns, true);
};

$projectCol = $hasCol('project_name') ? 'wr.project_name' : "'' AS project_name";
$mgrIdCol = $hasCol('manager_id') ? 'wr.manager_id' : 'NULL AS manager_id';
$mgrNameCol = $hasCol('manager_name') ? 'wr.manager_name' : "'' AS manager_name";
$totalTimeCol = $hasCol('total_time') ? 'wr.total_time' : "'' AS total_time";
$remarksCol = $hasCol('admin_remarks') ? 'wr.admin_remarks' : "'' AS admin_remarks";

$sql = "SELECT
            wr.id,
            wr.user_id,
            u.full_name AS user_name,
            {$projectCol},
            {$mgrIdCol},
            {$mgrNameCol},
            wr.report_date,
            wr.description,
            {$totalTimeCol},
            {$remarksCol},
            wr.status,
            wr.created_at
        FROM work_reports AS wr
        INNER JOIN users AS u ON u.id = wr.user_id";
$parameters = [];

if ($filterUserId) {
    $sql .= ' WHERE wr.user_id = :filter_user_id';
    $parameters['filter_user_id'] = $filterUserId;
} elseif ($userId !== null) {
    // If scope is 'all' and user is admin or HR, show all reports; otherwise show user\'s own reports
    if (!($scope === 'all' && $isAdminOrHr)) {
        $sql .= ' WHERE wr.user_id = :user_id';
        $parameters['user_id'] = $userId;
    }
}
$sql .= ' ORDER BY wr.report_date DESC, wr.id DESC';

try {
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $reports = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Work report listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve work reports.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Work reports retrieved successfully.',
    'data' => $reports,
]);
