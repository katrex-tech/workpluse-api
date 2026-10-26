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
        SELECT 
            id,
            employee_id,
            full_name,
            email,
            mobile,
            department,
            designation,
            role,
            profile_photo,
            status
        FROM users
        WHERE status = 'active'
          AND LOWER(TRIM(role)) IN ('hr', 'super_admin', 'tl', 'team_lead')
        ORDER BY 
            CASE 
                WHEN LOWER(TRIM(role)) = 'super_admin' THEN 1
                WHEN LOWER(TRIM(role)) = 'hr' THEN 2
                ELSE 3
            END,
            full_name ASC
    ");
    $stmt->execute();
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($managers as $m) {
        $rawRole = strtolower(trim($m['role'] ?? ''));
        $roleName = $m['role'];
        if ($rawRole === 'super_admin') {
            $roleName = 'Super Admin';
        } elseif ($rawRole === 'hr') {
            $roleName = 'HR';
        } elseif ($rawRole === 'tl' || $rawRole === 'team_lead') {
            $roleName = 'Team Lead';
        }

        $formatted[] = [
            'id' => (int) $m['id'],
            'employee_id' => $m['employee_id'] ?? 'EMP-' . $m['id'],
            'full_name' => $m['full_name'],
            'name' => $m['full_name'],
            'role' => $roleName,
            'department' => !empty($m['department']) ? $m['department'] : 'General',
            'designation' => !empty($m['designation']) ? $m['designation'] : $roleName,
            'email' => $m['email'],
            'mobile' => $m['mobile'] ?? '',
            'avatar_url' => formatMediaUrl($m['profile_photo']) ?? '',
            'profile_photo' => formatMediaUrl($m['profile_photo']),
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Reporting managers retrieved successfully.',
        'data' => $formatted,
    ], 200);
} catch (PDOException $e) {
    error_log('Failed to fetch managers: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to fetch managers: ' . $e->getMessage(),
        'data' => [],
    ], 500);
}
