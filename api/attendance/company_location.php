<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $stmt = $pdo->query('
            SELECT id, name, address, latitude, longitude, radius_meters, is_active, updated_at
            FROM company_locations
            WHERE is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ');
        $location = $stmt->fetch();

        if (!$location) {
            // Default fallback if table is empty
            $location = [
                'id' => 1,
                'name' => 'Headquarters HQ',
                'address' => 'Tech Tower HQ - Level 14',
                'latitude' => 21.14580000,
                'longitude' => 79.08820000,
                'radius_meters' => 200,
                'is_active' => 1,
            ];
        }

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Company location retrieved successfully.',
            'data' => [
                'id' => (int) $location['id'],
                'name' => $location['name'],
                'address' => $location['address'],
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'radius_meters' => (int) $location['radius_meters'],
                'is_active' => (bool) $location['is_active'],
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Failed to fetch company location: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Unable to fetch company location.',
            'data' => null,
        ], 500);
    }
} elseif ($method === 'POST') {
    // Super Admin or HR can update company location coordinates
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

    $latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
    $longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
    $radius = isset($input['radius_meters']) ? (int) $input['radius_meters'] : 200;
    $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : 'Headquarters HQ';
    $address = isset($input['address']) && is_string($input['address']) ? trim($input['address']) : 'Tech Tower HQ - Level 14';

    if ($latitude === null || $longitude === null) {
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Latitude and Longitude are required.',
            'data' => null,
        ], 422);
    }

    try {
        $existing = $pdo->query('SELECT id FROM company_locations ORDER BY id ASC LIMIT 1')->fetch();
        if ($existing) {
            $update = $pdo->prepare('
                UPDATE company_locations
                SET name = :name, address = :address, latitude = :latitude, longitude = :longitude, radius_meters = :radius, is_active = 1
                WHERE id = :id
            ');
            $update->execute([
                'name' => $name,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius' => $radius,
                'id' => $existing['id'],
            ]);
            $locationId = (int) $existing['id'];
        } else {
            $insert = $pdo->prepare('
                INSERT INTO company_locations (name, address, latitude, longitude, radius_meters, is_active)
                VALUES (:name, :address, :latitude, :longitude, :radius, 1)
            ');
            $insert->execute([
                'name' => $name,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius' => $radius,
            ]);
            $locationId = (int) $pdo->lastInsertId();
        }

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Company location updated successfully.',
            'data' => [
                'id' => $locationId,
                'name' => $name,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $radius,
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Failed to update company location: ' . $e->getMessage());
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Unable to save company location.',
            'data' => null,
        ], 500);
    }
} else {
    header('Allow: GET, POST, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}
