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
        SELECT id, module_key, title, subtitle, icon, color, route, badge, is_active, sort_order
        FROM menu_modules
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute();
    $modules = $stmt->fetchAll();

    // Normalize types
    foreach ($modules as &$mod) {
        $mod['id'] = (int) $mod['id'];
        $mod['is_active'] = (bool) $mod['is_active'];
        $mod['sort_order'] = (int) $mod['sort_order'];
    }
    unset($mod);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Menu modules retrieved successfully.',
        'count' => count($modules),
        'data' => $modules,
    ], 200);
} catch (PDOException $e) {
    error_log('Menu modules query failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load menu modules.',
        'data' => [],
    ], 500);
}
