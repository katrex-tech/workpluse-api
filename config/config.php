<?php
date_default_timezone_set('Asia/Kolkata');

$config = [
    'environment' => 'local',
    'database' => [
        'host' => 'localhost',
        'name' => 'workpulse',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'jwt_secret' => 'workpulse_secret_key_12345',
    'jwt_expire' => 7 * 24 * 60 * 60,
    'compreface' => [
        'url' => getenv('COMPREFACE_URL') ?: 'http://127.0.0.1:8000',
        'api_key' => getenv('COMPREFACE_API_KEY') ?: 'wp_master_secret_key_2026',
        'similarity_threshold' => (float)(getenv('COMPREFACE_THRESHOLD') ?: 0.65),
        'timeout' => 15,
    ],
    'liveness' => [
        'challenge_expiry_seconds' => 180, // 3 minutes
        'min_confidence_score' => 0.70,
    ],
];

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'Server Error: ' . $e->getMessage(),
        'data' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
});

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse(array $data, int $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}

if (!function_exists('formatMediaUrl')) {
    function formatMediaUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        $trimmed = trim($path);
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'data:')) {
            return $trimmed;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '192.168.1.17';
        $cleanPath = ltrim($trimmed, '/');
        return "$scheme://$host/workpulse-api/$cleanPath";
    }
}

return $config;
