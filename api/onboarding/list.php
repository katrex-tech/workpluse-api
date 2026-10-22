<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

try {
    $stmt = $pdo->prepare("
        SELECT id, title, subtitle, badge_text, icon, sort_order
        FROM onboarding_features
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute();
    $features = $stmt->fetchAll();

    foreach ($features as &$f) {
        $f['id'] = (int) $f['id'];
        $f['sort_order'] = (int) $f['sort_order'];
    }
    unset($f);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Onboarding data retrieved successfully.',
        'app_info' => [
            'app_name' => 'WorkPulse',
            'tagline' => 'Smart Attendance & HR Management',
            'description' => "Punch in, manage leaves, track your work\n— all in one intuitive place.",
            'version' => 'Version 2.4.0',
            'security_badge' => 'ENTERPRISE READY',
            'sso_text' => 'SSO Secured',
        ],
        'data' => $features,
    ], 200);
} catch (PDOException $e) {
    error_log('Onboarding query failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load onboarding features.',
        'data' => [],
    ], 500);
}
