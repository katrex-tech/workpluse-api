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
$faceMatched = isset($input['face_matched']) ? (bool) $input['face_matched'] : true;

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    // 1. Verify user exists and check face registration status
    $userQuery = $pdo->prepare('
        SELECT id, full_name, employee_id, face_registered, face_data 
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
    if (empty($user['face_registered'])) {
        sendJsonResponse([
            'status' => 'error',
            'error_code' => 'FACE_NOT_REGISTERED',
            'message' => 'Face not registered. Please register your face in Face Management before punching in.',
            'data' => null,
        ], 403);
    }

    if ($faceMatched === false) {
        sendJsonResponse([
            'status' => 'error',
            'error_code' => 'FACE_MISMATCH',
            'message' => 'Wrong face detected. Biometric verification failed.',
            'data' => null,
        ], 403);
    }

    // 3. Geofence Verification against active Company Location
    $companyStmt = $pdo->query('
        SELECT id, name, address, latitude, longitude, radius_meters 
        FROM company_locations 
        WHERE is_active = 1 
        ORDER BY id ASC 
        LIMIT 1
    ');
    $companyLoc = $companyStmt->fetch();

    if ($companyLoc && $companyLoc['latitude'] !== null && $companyLoc['longitude'] !== null) {
        if ($latitude === null || $longitude === null) {
            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'LOCATION_REQUIRED',
                'message' => 'GPS Location coordinates are required for attendance verification.',
                'data' => null,
            ], 422);
        }

        $earthRadius = 6371000; // in meters
        $compLat = (float) $companyLoc['latitude'];
        $compLng = (float) $companyLoc['longitude'];
        $dLat = deg2rad($latitude - $compLat);
        $dLon = deg2rad($longitude - $compLng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($compLat)) * cos(deg2rad($latitude)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceMeters = round($earthRadius * $c);

        $allowedRadius = (int) ($companyLoc['radius_meters'] ?? 200);
        if ($distanceMeters > $allowedRadius) {
            sendJsonResponse([
                'status' => 'error',
                'error_code' => 'OUT_OF_RANGE',
                'message' => "You are out of range ({$distanceMeters}m away). Allowed radius is {$allowedRadius}m. Please go to company location.",
                'data' => [
                    'distance_meters' => $distanceMeters,
                    'allowed_radius_meters' => $allowedRadius,
                    'company_name' => $companyLoc['name'],
                    'company_latitude' => $compLat,
                    'company_longitude' => $compLng,
                    'user_latitude' => $latitude,
                    'user_longitude' => $longitude,
                ],
            ], 403);
        }
    }

    // 4. Check for an existing open punch in today
    $check = $pdo->prepare('
        SELECT id, punch_in, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today AND punch_out IS NULL
        LIMIT 1
    ');
    $check->execute(['user_id' => $userId, 'today' => $today]);
    $openAttendance = $check->fetch();

    if ($openAttendance) {
        $clockInFormatted = date('h:i A', strtotime($openAttendance['punch_in']));
        sendJsonResponse([
            'status' => 'error',
            'message' => 'You are already punched in for today.',
            'data' => [
                'id' => (int) $openAttendance['id'],
                'user_id' => $userId,
                'date' => $today,
                'punch_in' => $openAttendance['punch_in'],
                'clock_in_time' => $clockInFormatted,
                'is_punched_in' => true,
                'status' => $openAttendance['status'],
            ],
        ], 409);
    }

    // 5. Insert new attendance record
    $insert = $pdo->prepare('
        INSERT INTO attendance (user_id, date, punch_in, status, latitude, longitude, address)
        VALUES (:user_id, :date, :punch_in, :status, :latitude, :longitude, :address)
    ');
    $insert->execute([
        'user_id' => $userId,
        'date' => $today,
        'punch_in' => $now,
        'status' => 'punched_in',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'address' => $address,
    ]);

    $newId = (int) $pdo->lastInsertId();
    $clockInFormatted = date('h:i A', strtotime($now));

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Punched in successfully.',
        'data' => [
            'id' => $newId,
            'user_id' => $userId,
            'date' => $today,
            'punch_in' => $now,
            'clock_in_time' => $clockInFormatted,
            'is_punched_in' => true,
            'status' => 'punched_in',
        ],
    ], 201);
} catch (PDOException $e) {
    error_log('Punch-in failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to record punch in: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
