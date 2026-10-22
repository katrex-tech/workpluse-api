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
require_once __DIR__ . '/../../config/auth_check.php';

$userId = getAuthenticatedUserId(false);
$caller = $userId ? getAuthenticatedUser(false) : null;
$role = strtolower(trim($caller['role'] ?? ''));

try {
    $stmt = $pdo->prepare("
        SELECT id, module_key, title, subtitle, icon, color, route, badge, is_active, sort_order
        FROM menu_modules
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute();
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Default modules fallback if table is empty
    if (empty($modules)) {
        $modules = [
            ['id' => 11, 'module_key' => 'face_management', 'title' => 'Face ID Management', 'subtitle' => 'Register & manage facial biometrics', 'icon' => 'face', 'color' => '#00B4A0', 'route' => 'face_management', 'badge' => 'SECURE', 'is_active' => true, 'sort_order' => 1],
            ['id' => 1, 'module_key' => 'leave', 'title' => 'Leave Management', 'subtitle' => 'Apply, track balances & holidays', 'icon' => 'calendar_month', 'color' => '#3F51B5', 'route' => 'leave', 'badge' => null, 'is_active' => true, 'sort_order' => 2],
            ['id' => 2, 'module_key' => 'timesheet', 'title' => 'Timesheet & Shifts', 'subtitle' => 'Clock-in logs & work hours', 'icon' => 'schedule', 'color' => '#00897B', 'route' => 'timesheet', 'badge' => null, 'is_active' => true, 'sort_order' => 3],
            ['id' => 3, 'module_key' => 'id_card', 'title' => 'Digital ID Card', 'subtitle' => 'Official employee verification badge', 'icon' => 'badge', 'color' => '#E53935', 'route' => 'id_card', 'badge' => 'VERIFIED', 'is_active' => true, 'sort_order' => 4],
            ['id' => 4, 'module_key' => 'tasks', 'title' => 'Sprint Tasks', 'subtitle' => 'Active deliverables & sprint board', 'icon' => 'assignment', 'color' => '#00A896', 'route' => 'tasks', 'badge' => 'DUE', 'is_active' => true, 'sort_order' => 5],
            ['id' => 5, 'module_key' => 'circular', 'title' => 'Notice Circulars', 'subtitle' => 'Executive announcements & notices', 'icon' => 'campaign', 'color' => '#FB8C00', 'route' => 'circular', 'badge' => 'NEW', 'is_active' => true, 'sort_order' => 6],
            ['id' => 6, 'module_key' => 'payslip', 'title' => 'Salary Payslips', 'subtitle' => 'Monthly compensation & tax statements', 'icon' => 'receipt_long', 'color' => '#43A047', 'route' => 'payslip', 'badge' => null, 'is_active' => true, 'sort_order' => 7],
            ['id' => 7, 'module_key' => 'assets', 'title' => 'Company Assets', 'subtitle' => 'Assigned devices & peripherals', 'icon' => 'devices', 'color' => '#8E24AA', 'route' => 'assets', 'badge' => null, 'is_active' => true, 'sort_order' => 8],
            ['id' => 8, 'module_key' => 'work_report', 'title' => 'Daily Work Reports', 'subtitle' => 'Submit standup & task reports', 'icon' => 'summarize', 'color' => '#00ACC1', 'route' => 'work_report', 'badge' => null, 'is_active' => true, 'sort_order' => 9],
            ['id' => 9, 'module_key' => 'team', 'title' => 'Team Directory', 'subtitle' => 'Find colleagues & reporting tree', 'icon' => 'people', 'color' => '#1E88E5', 'route' => 'team', 'badge' => null, 'is_active' => true, 'sort_order' => 10],
            ['id' => 10, 'module_key' => 'gallery', 'title' => 'Event Gallery', 'subtitle' => 'Office celebrations & summits', 'icon' => 'photo_library', 'color' => '#5E35B1', 'route' => 'gallery', 'badge' => null, 'is_active' => true, 'sort_order' => 11],
        ];
    }

    // Role-based privilege: If role is super_admin or hr, prepend Employee Onboarding!
    if ($role === 'super_admin' || $role === 'hr') {
        array_unshift($modules, [
            'id' => 999,
            'module_key' => 'onboarding',
            'title' => 'Onboard Employee',
            'subtitle' => 'Add new employees, TLs & HR staff',
            'icon' => 'person_add',
            'color' => '#00A896',
            'route' => 'employee_onboarding',
            'badge' => strtoupper($role),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

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
