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

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!is_string($authorization) || !preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $tokenMatch)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'A valid bearer token is required.',
        'data' => [],
    ], 401);
}

$tokenParts = explode('.', $tokenMatch[1]);
if (count($tokenParts) !== 3) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Invalid bearer token.',
        'data' => [],
    ], 401);
}

$base64UrlDecode = static function ($value) {
    $remainder = strlen($value) % 4;
    if ($remainder !== 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
};

$headerJson = $base64UrlDecode($tokenParts[0]);
$claimsJson = $base64UrlDecode($tokenParts[1]);
$signature = $base64UrlDecode($tokenParts[2]);
if ($headerJson === false || $claimsJson === false || $signature === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Invalid bearer token.',
        'data' => [],
    ], 401);
}

try {
    $tokenHeader = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
    $claims = json_decode($claimsJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Invalid bearer token.',
        'data' => [],
    ], 401);
}

$expectedSignature = hash_hmac(
    'sha256',
    $tokenParts[0] . '.' . $tokenParts[1],
    $config['jwt_secret'],
    true
);
if (
    !is_array($tokenHeader)
    || ($tokenHeader['alg'] ?? null) !== 'HS256'
    || !is_array($claims)
    || !isset($claims['sub'], $claims['exp'])
    || !is_scalar($claims['sub'])
    || !is_numeric($claims['exp'])
    || (int) $claims['exp'] <= time()
    || !hash_equals($expectedSignature, $signature)
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Invalid or expired bearer token.',
        'data' => [],
    ], 401);
}

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

$title = isset($input['title']) && is_string($input['title'])
    ? trim($input['title'])
    : '';
$description = isset($input['description']) && is_string($input['description'])
    ? trim($input['description'])
    : '';
$createdByInput = $input['created_by'] ?? null;
$createdBy = is_string($createdByInput) || is_int($createdByInput)
    ? filter_var($createdByInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$attachment = $input['attachment'] ?? null;

if (
    $title === ''
    || strlen($title) > 255
    || $description === ''
    || $createdBy === false
    || !ctype_digit((string) $claims['sub'])
    || (int) $claims['sub'] !== $createdBy
    || ($attachment !== null && (!is_string($attachment) || strlen($attachment) > 2048))
) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid title, description, and created_by matching the authenticated user are required. attachment must be a string when provided.',
        'data' => [],
    ], 422);
}

try {
    $userQuery = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $createdBy]);
    $creator = $userQuery->fetch();

    if ($creator === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Circular creator not found.',
            'data' => [],
        ], 404);
    }

    $role = strtolower(trim(str_replace('_', ' ', $creator['role'])));
    if (!in_array($role, ['super admin', 'tl', 'team lead'], true)) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Only Super Admin and TL users can create circulars.',
            'data' => [],
        ], 403);
    }

    $insert = $pdo->prepare(
        'INSERT INTO circulars (title, description, attachment, created_by)
         VALUES (:title, :description, :attachment, :created_by)'
    );
    $insert->execute([
        'title' => $title,
        'description' => $description,
        'attachment' => $attachment,
        'created_by' => $createdBy,
    ]);
    $circularId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Circular creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to create circular.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Circular created successfully.',
    'data' => [
        'id' => $circularId,
    ],
], 201);
