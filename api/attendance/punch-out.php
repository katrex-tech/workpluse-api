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
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$rawBody = file_get_contents('php://input');
$input = [];
if (!empty($rawBody)) {
    try {
        $input = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Request body must be valid JSON.',
            'data' => null,
        ], 400);
    }
}

$userId = isset($input['user_id']) ? (int) $input['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

$latitude = isset($input['latitude']) && $input['latitude'] !== null ? (float) $input['latitude'] : null;
$longitude = isset($input['longitude']) && $input['longitude'] !== null ? (float) $input['longitude'] : null;
$address = isset($input['address']) && is_string($input['address']) ? trim($input['address']) : null;

// Strict anti-proxy enforcement: face_matched must be explicitly true/1
$faceMatched = false;
if (isset($input['face_matched'])) {
    $rawVal = $input['face_matched'];
    if ($rawVal === true || $rawVal === 1 || $rawVal === '1' || $rawVal === 'true') {
        $faceMatched = true;
    }
}
$faceData = isset($input['face_data']) && is_string($input['face_data']) ? trim($input['face_data']) : null;

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    // 1. Verify user exists and check face registration status
    $userQuery = $pdo->prepare('
        SELECT id, full_name, employee_id, face_registered, face_data, registered_lat, registered_lng, registered_address, work_location 
        FROM users 
        WHERE id = :id 
        LIMIT 1
    ');
    $userQuery->execute(['id' => $userId]);
    $user = $userQuery->fetch();
    if (!$user) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }

    // 2. Face Registration & Biometric Match Verification
    if (empty($user['face_registered']) || empty($user['face_data'])) {
        sendJsonResponse([
            'status' => 'error',
            'error_code' => 'FACE_NOT_REGISTERED',
            'message' => 'Face biometrics not registered for employee ' . htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['employee_id'] ?? 'ID:' . $userId) . '). Registration in Face Management is required before attendance can be recorded.',
            'data' => null,
        ], 403);
    }

    if (!$faceMatched) {
        sendJsonResponse([
            'status' => 'error',
            'error_code' => 'FACE_MISMATCH',
            'message' => 'Wrong face detected for employee ' . htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['employee_id'] ?? 'ID:' . $userId) . '). Biometric face verification failed to prevent proxy attendance.',
            'data' => [
                'user_id' => $userId,
                'employee_id' => $user['employee_id'] ?? null,
                'full_name' => $user['full_name'] ?? null,
                'face_matched' => false,
            ],
        ], 403);
    }

    // 3. Geofence Verification against assigned employee workplace coordinates or company location
    $targetLat = null;
    $targetLng = null;
    $targetName = null;
    $allowedRadius = 200;

    if (!empty($user['registered_lat']) && !empty($user['registered_lng'])) {
        $targetLat = (float) $user['registered_lat'];
        $targetLng = (float) $user['registered_lng'];
        $targetName = !empty($user['work_location']) ? $user['work_location'] : (!empty($user['registered_address']) ? $user['registered_address'] : 'Assigned Workplace');
    } else {
        $companyStmt = $pdo->query('
            SELECT id, name, address, latitude, longitude, radius_meters 
            FROM company_locations 
            WHERE is_active = 1 
            ORDER BY id ASC 
            LIMIT 1
        ');
        $companyLoc = $companyStmt->fetch();
        if ($companyLoc && $companyLoc['latitude'] !== null && $companyLoc['longitude'] !== null) {
            $targetLat = (float) $companyLoc['latitude'];
            $targetLng = (float) $companyLoc['longitude'];
            $targetName = $companyLoc['name'];
            $allowedRadius = (int) ($companyLoc['radius_meters'] ?? 200);
        }
    }

    if ($targetLat !== null && $targetLng !== null) {
        if ($latitude === null || $longitude === null) {
            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'LOCATION_REQUIRED',
                'message' => 'GPS Location coordinates are required for attendance verification.',
                'data' => null,
            ], 422);
        }

        $earthRadius = 6371000; // in meters
        $dLat = deg2rad($latitude - $targetLat);
        $dLon = deg2rad($longitude - $targetLng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($targetLat)) * cos(deg2rad($latitude)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceMeters = round($earthRadius * $c);

        if ($distanceMeters > $allowedRadius) {
            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'OUT_OF_RANGE',
                'message' => "You are out of range ({$distanceMeters}m away). Allowed radius is {$allowedRadius}m from {$targetName}. Please go to your assigned work location.",
                'data' => [
                    'distance_meters' => $distanceMeters,
                    'allowed_radius_meters' => $allowedRadius,
                    'company_name' => $targetName,
                    'company_latitude' => $targetLat,
                    'company_longitude' => $targetLng,
                    'user_latitude' => $latitude,
                    'user_longitude' => $longitude,
                ],
            ], 403);
        }
    }

    // 4. Find open attendance record
    $select = $pdo->prepare('
        SELECT id, punch_in, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today AND punch_out IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $select->execute(['user_id' => $userId, 'today' => $today]);
    $openAttendance = $select->fetch();

    if (!$openAttendance) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'No active punch-in was found for today.',
            'data' => null,
        ], 404);
    }

    $inTimestamp = strtotime($openAttendance['punch_in']);
    $outTimestamp = strtotime($now);
    $diffSeconds = max(0, $outTimestamp - $inTimestamp);
    $workingHours = round($diffSeconds / 3600, 2);

    $hours = floor($diffSeconds / 3600);
    $minutes = floor(($diffSeconds % 3600) / 60);
    $hoursLogged = "{$hours}h {$minutes}m";

    $update = $pdo->prepare('
        UPDATE attendance
        SET punch_out = :punch_out,
            working_hours = :working_hours,
            status = :status,
            punch_out_latitude = :latitude,
            punch_out_longitude = :longitude,
            punch_out_address = :address
        WHERE id = :id
    ');
    $update->execute([
        'punch_out' => $now,
        'working_hours' => $workingHours,
        'status' => 'completed',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'address' => $address,
        'id' => $openAttendance['id'],
    ]);

    $clockInFormatted = date('h:i A', $inTimestamp);
    $clockOutFormatted = date('h:i A', $outTimestamp);

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Punched out successfully.',
        'data' => [
            'id' => (int) $openAttendance['id'],
            'user_id' => $userId,
            'date' => $today,
            'punch_in' => $openAttendance['punch_in'],
            'punch_out' => $now,
            'clock_in_time' => $clockInFormatted,
            'clock_out_time' => $clockOutFormatted,
            'working_hours' => $workingHours,
            'hours_logged' => $hoursLogged,
            'is_punched_in' => false,
            'status' => 'completed',
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Punch-out failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch out: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
