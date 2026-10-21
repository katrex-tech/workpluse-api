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
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $userId]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'User not found.',
            'data' => [],
        ], 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO work_reports (user_id, report_date, description, status)
         VALUES (:user_id, :report_date, :description, :status)'
    );
    $insert->execute([
        'user_id' => $userId,
        'report_date' => $reportDate,
        'description' => $description,
        'status' => 'submitted',
    ]);
    $reportId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Work report submission failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to submit work report.',
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
