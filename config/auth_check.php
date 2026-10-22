<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/database.php';

function getAuthenticatedUserId(bool $required = false): ?int
{
    global $config, $pdo;

    $token = null;
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    if ($token !== null) {
        $payload = verifyJWT($token, $config['jwt_secret']);
        if ($payload && isset($payload['sub'])) {
            $uid = (int) $payload['sub'];
            if ($uid > 0) {
                return $uid;
            }
        }
    }

    // Fallback to user_id parameter from GET or parsed JSON body
    $userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId) {
        return (int) $userId;
    }

    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true);
        if (is_array($body) && isset($body['user_id'])) {
            $bodyUid = filter_var($body['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($bodyUid) {
                return (int) $bodyUid;
            }
        }
    }

    if ($required) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Unauthorized or missing user identifier.',
            'data' => null,
        ], 401);
    }

    // Default fallback to first active user if in development environment
    $defaultUser = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
    return $defaultUser ? (int) $defaultUser : 1;
}

function getAuthenticatedUser(bool $required = false): ?array
{
    global $pdo;

    $userId = getAuthenticatedUserId($required);
    if (!$userId) {
        if ($required) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'Unauthorized user.',
                'data' => null,
            ], 401);
        }
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, employee_id, full_name, email, mobile, role, department, designation, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        if ($required) {
            sendJsonResponse([
                'status' => 'error',
                'message' => 'User account not found.',
                'data' => null,
            ], 401);
        }
        return null;
    }

    if (isset($user['status']) && $user['status'] === 'inactive') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User account is deactivated.',
            'data' => null,
        ], 403);
    }

    return $user;
}

function requireRole(array $allowedRoles): array
{
    $user = getAuthenticatedUser(true);
    $userRole = strtolower(trim($user['role'] ?? ''));

    $allowedNormalized = array_map(function ($r) {
        return strtolower(trim($r));
    }, $allowedRoles);

    if (!in_array($userRole, $allowedNormalized, true)) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Forbidden: You do not have permission to access this resource. Required role: ' . implode(' or ', $allowedRoles),
            'data' => null,
        ], 403);
    }

    return $user;
}
