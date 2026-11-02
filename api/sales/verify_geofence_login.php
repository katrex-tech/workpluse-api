<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. POST required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../includes/SalesService.php';

$rawBody = file_get_contents('php://input');
try {
    $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Request body must be valid JSON.',
        'data' => null,
    ], 400);
}

$identifier = trim($input['identifier'] ?? ($input['email'] ?? ($input['mobile'] ?? '')));
$password = (string)($input['password'] ?? '');
$latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
$longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;

if ($identifier === '' || $password === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Email/Mobile and password are required.',
        'data' => null,
    ], 422);
}

try {
    $stmt = $pdo->prepare('
        SELECT id, employee_id, full_name, email, password, mobile, role, department, designation, status, must_change_password,
               is_field_sales, daily_meeting_target, base_lat, base_lng, base_address, registered_lat, registered_lng, registered_address
        FROM users
        WHERE LOWER(email) = :email OR mobile = :mobile
        LIMIT 1
    ');
    $stmt->execute([
        'email' => strtolower($identifier),
        'mobile' => $identifier,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $isPasswordValid = false;
    if ($user) {
        if ($user['password'] === $password) {
            $isPasswordValid = true;
        } elseif (password_verify($password, $user['password'])) {
            $isPasswordValid = true;
        }
    }

    if (!$user || !$isPasswordValid) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Invalid email or password.',
            'data' => null,
        ], 401);
    }

    if (($user['status'] ?? 'active') === 'inactive') {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Your account has been deactivated. Please contact your HR or Super Admin.',
            'data' => null,
        ], 403);
    }

    $isFieldSales = !empty($user['is_field_sales']) || strtolower($user['role'] ?? '') === 'sales_executive';

    // Step 1 Geofenced Login Check: ONLY applies to Field Sales Executives!
    if ($isFieldSales) {
        if ($latitude === null || $longitude === null) {
            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'GPS_LOCATION_REQUIRED',
                'message' => 'Device GPS location is required to verify field sales 5 KM geofenced login.',
                'data' => [
                    'is_field_sales' => true,
                ],
            ], 422);
        }

        $geoCheck = SalesService::verifyGeofenceLogin($user, $latitude, $longitude);

        if (!$geoCheck['allowed']) {
            // User is INSIDE the 5 KM zone -> BLOCK LOGIN
            // Log alert
            try {
                $alertStmt = $pdo->prepare('
                    INSERT INTO sales_location_alerts (user_id, alert_type, latitude, longitude, message, created_at)
                    VALUES (:uid, "geofence_violation", :lat, :lng, :msg, NOW())
                ');
                $alertStmt->execute([
                    'uid' => $user['id'],
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'msg' => 'Login attempt blocked: within 5 KM of base (' . $geoCheck['current_distance_km'] . ' KM)',
                ]);
            } catch (_) {}

            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'WITHIN_5KM_GEOFENCE',
                'message' => $geoCheck['message'],
                'data' => [
                    'is_field_sales' => true,
                    'current_distance_km' => $geoCheck['current_distance_km'],
                    'required_min_distance_km' => $geoCheck['required_min_distance_km'],
                    'base_location' => $geoCheck['base_location'],
                ],
            ], 403);
        }

        // Outside 5 KM zone -> Log the initial login coordinate
        try {
            $logStmt = $pdo->prepare('
                INSERT INTO sales_tracking_logs (user_id, latitude, longitude, accuracy, speed, battery_level, recorded_at, created_at)
                VALUES (:uid, :lat, :lng, 10.0, 0.0, 100, NOW(), NOW())
            ');
            $logStmt->execute([
                'uid' => $user['id'],
                'lat' => $latitude,
                'lng' => $longitude,
            ]);
        } catch (_) {}
    }

    // Generate JWT token
    $token = generateJWT([
        'sub' => (string) $user['id'],
        'employee_id' => $user['employee_id'] ?? '',
        'role' => $user['role'] ?? 'employee',
        'is_field_sales' => $isFieldSales,
    ], $config['jwt_secret'], $config['jwt_expire']);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Login successful.',
        'token' => $token,
        'user' => [
            'id' => (int) $user['id'],
            'employee_id' => $user['employee_id'] ?? '',
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'mobile' => $user['mobile'] ?? '',
            'role' => $user['role'] ?? 'employee',
            'department' => $user['department'] ?? 'General',
            'designation' => $user['designation'] ?? 'Staff',
            'is_field_sales' => $isFieldSales,
            'daily_meeting_target' => (int) ($user['daily_meeting_target'] ?? 5),
            'base_lat' => !empty($user['base_lat']) ? (float)$user['base_lat'] : (!empty($user['registered_lat']) ? (float)$user['registered_lat'] : null),
            'base_lng' => !empty($user['base_lng']) ? (float)$user['base_lng'] : (!empty($user['registered_lng']) ? (float)$user['registered_lng'] : null),
            'base_address' => $user['base_address'] ?? ($user['registered_address'] ?? null),
            'profile_photo' => formatMediaUrl($user['profile_photo'] ?? null),
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('Sales login error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to process login at this time: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
