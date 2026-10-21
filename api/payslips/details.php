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
$payslipId = is_string($idInput) || is_int($idInput)
    ? filter_var($idInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($payslipId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid id query parameter is required.',
        'data' => [],
    ], 422);
}

try {
    $query = $pdo->prepare(
        'SELECT id, user_id, month, year, file_url, created_at
         FROM payslips
         WHERE id = :id
         LIMIT 1'
    );
    $query->execute(['id' => $payslipId]);
    $payslip = $query->fetch();
} catch (PDOException $exception) {
    error_log('Payslip detail lookup failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve payslip details.',
        'data' => [],
    ], 500);
}

if ($payslip === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Payslip not found.',
        'data' => [],
    ], 404);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Payslip retrieved successfully.',
    'data' => $payslip,
]);
