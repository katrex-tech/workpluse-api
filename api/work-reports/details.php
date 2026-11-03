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

$idInput = $_GET['id'] ?? null;
$reportId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
if ($reportId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
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

    $projectCol = $hasCol('project_name') ? 'wr.project_name' : "'' AS project_name";
    $mgrIdCol = $hasCol('manager_id') ? 'wr.manager_id' : 'NULL AS manager_id';
    $mgrNameCol = $hasCol('manager_name') ? 'wr.manager_name' : "'' AS manager_name";
    $totalTimeCol = $hasCol('total_time') ? 'wr.total_time' : "'' AS total_time";
    $remarksCol = $hasCol('admin_remarks') ? 'wr.admin_remarks' : "'' AS admin_remarks";

    $query = $pdo->prepare(
        "SELECT
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
         INNER JOIN users AS u ON u.id = wr.user_id
         WHERE wr.id = :id
         LIMIT 1"
    );
    $query->execute(['id' => $reportId]);
    $report = $query->fetch();
} catch (PDOException $exception) {
    error_log('Work report detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve work report details.',
        'data' => [],
    ], 500);
}

if ($report === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Work report not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Work report retrieved successfully.',
    'data' => $report,
]);
