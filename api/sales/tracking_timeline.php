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
        'message' => 'Method not allowed. GET required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../includes/SalesService.php';

$currentUser = getAuthenticatedUser();
$currentUserId = (int)$currentUser['id'];
$role = strtolower(trim($currentUser['role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'super_admin', 'sales_head', 'hr'], true);

$targetUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUserId;
if ($targetUserId !== $currentUserId && !$isAdmin) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Forbidden: You can only view your own tracking timeline.',
        'data' => null,
    ], 403);
}

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$ratePerKm = isset($_GET['rate_per_km']) && is_numeric($_GET['rate_per_km']) ? (float)$_GET['rate_per_km'] : 6.0;

try {
    // Fetch executive details
    $uStmt = $pdo->prepare('
        SELECT id, full_name, employee_id, designation, department, base_lat, base_lng, base_address,
               registered_lat, registered_lng, registered_address
        FROM users 
        WHERE id = :uid 
        LIMIT 1
    ');
    $uStmt->execute(['uid' => $targetUserId]);
    $executive = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$executive) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Executive user not found.',
            'data' => null,
        ], 404);
    }

    $timeline = SalesService::calculateTimelineAnalytics($pdo, $targetUserId, $date, $ratePerKm);

    // Format meeting photo URLs
    foreach ($timeline['meetings'] as &$m) {
        $m['id'] = (int)$m['id'];
        $m['latitude'] = (float)$m['latitude'];
        $m['longitude'] = (float)$m['longitude'];
        $m['formatted_time'] = date('h:i A', strtotime($m['check_in_time']));
    }
    unset($m);

    // Base origin point
    $baseLat = !empty($executive['base_lat']) ? (float)$executive['base_lat'] : (!empty($executive['registered_lat']) ? (float)$executive['registered_lat'] : 21.14580000);
    $baseLng = !empty($executive['base_lng']) ? (float)$executive['base_lng'] : (!empty($executive['registered_lng']) ? (float)$executive['registered_lng'] : 79.08820000);
    $baseAddress = $executive['base_address'] ?: ($executive['registered_address'] ?: 'Office Base HQ');

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Timeline analytics generated successfully.',
        'data' => array_merge($timeline, [
            'executive' => [
                'id' => (int)$executive['id'],
                'name' => $executive['full_name'],
                'employee_id' => $executive['employee_id'],
                'designation' => $executive['designation'],
                'department' => $executive['department'],
                'base_origin' => [
                    'latitude' => $baseLat,
                    'longitude' => $baseLng,
                    'address' => $baseAddress,
                ],
            ],
        ]),
    ], 200);

} catch (PDOException $e) {
    error_log('tracking_timeline error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to generate timeline: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
