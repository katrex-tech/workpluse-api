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
$userId = is_string($userIdInput) || is_int($userIdInput)
    ? filter_var($userIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;

if ($userId === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid user_id query parameter is required.',
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

    $query = $pdo->prepare(
        'SELECT id, user_id, month, year, file_url, created_at
         FROM payslips
         WHERE user_id = :user_id
         ORDER BY year DESC, month DESC, id DESC'
    );
    $query->execute(['user_id' => $userId]);
    $payslips = $query->fetchAll();
} catch (PDOException $exception) {
    error_log('Payslip listing failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to retrieve payslips.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Payslips retrieved successfully.',
    'data' => $payslips,
]);
