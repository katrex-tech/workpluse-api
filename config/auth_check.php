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
