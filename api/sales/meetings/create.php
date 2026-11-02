<?php

require_once __DIR__ . '/../../../config/config.php';

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

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/auth_check.php';

$userId = getAuthenticatedUserId();

// Extract fields (support both multipart form-data and application/json)
$rawBody = file_get_contents('php://input');
$jsonInput = [];
if (!empty($rawBody)) {
    try {
        $jsonInput = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $jsonInput = [];
    }
}
$inputData = !empty($_POST) ? $_POST : $jsonInput;

$clientName = trim($inputData['client_name'] ?? ($inputData['company_name'] ?? ''));
$clientPhone = trim($inputData['client_phone'] ?? ($inputData['phone'] ?? ''));
$meetingPurpose = trim($inputData['meeting_purpose'] ?? ($inputData['purpose'] ?? ''));
$notes = trim($inputData['notes'] ?? ($inputData['summary'] ?? ''));
$latitude = isset($inputData['latitude']) && is_numeric($inputData['latitude']) ? (float)$inputData['latitude'] : null;
$longitude = isset($inputData['longitude']) && is_numeric($inputData['longitude']) ? (float)$inputData['longitude'] : null;
$locationAddress = trim($inputData['location_address'] ?? ($inputData['address'] ?? 'Client Location'));
$checkInTime = !empty($inputData['check_in_time']) ? $inputData['check_in_time'] : date('Y-m-d H:i:s');

if ($clientName === '') {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Client / Company Name is required.',
        'data' => null,
    ], 422);
}

if ($latitude === null || $longitude === null) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Live GPS coordinates are mandatory for meeting check-in.',
        'data' => null,
    ], 422);
}

// 1. Save uploaded meeting photo
$uploadDir = __DIR__ . '/../../../uploads/sales_meetings';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

$uploadedFile = $_FILES['file'] ?? ($_FILES['photo'] ?? ($_FILES['image'] ?? null));
$savedPhotoRelPath = null;

if ($uploadedFile && !empty($uploadedFile['tmp_name']) && is_uploaded_file($uploadedFile['tmp_name'])) {
    $ext = pathinfo($uploadedFile['name'] ?? 'photo.jpg', PATHINFO_EXTENSION) ?: 'jpg';
    $fileName = 'meeting_' . $userId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
        $savedPhotoRelPath = 'uploads/sales_meetings/' . $fileName;
    }
} elseif (!empty($inputData['photo_base64']) || !empty($inputData['photo'])) {
    $rawBase64 = !empty($inputData['photo_base64']) ? $inputData['photo_base64'] : $inputData['photo'];
    if (preg_match('/^data:image\/(\w+);base64,/', $rawBase64)) {
        $rawBase64 = substr($rawBase64, strpos($rawBase64, ',') + 1);
    }
    $decoded = base64_decode($rawBase64, true);
    if ($decoded !== false) {
        $fileName = 'meeting_' . $userId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
        $destPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($destPath, $decoded) !== false) {
            $savedPhotoRelPath = 'uploads/sales_meetings/' . $fileName;
        }
    }
}

if (!$savedPhotoRelPath) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'A live camera photo with location watermark is required for meeting verification.',
        'data' => null,
    ], 422);
}

try {
    // 2. Insert into sales_meetings with pending status
    $stmt = $pdo->prepare('
        INSERT INTO sales_meetings (
            user_id, client_name, client_phone, meeting_purpose, notes,
            photo_path, watermarked_photo_path, latitude, longitude,
            location_address, check_in_time, status, created_at
        ) VALUES (
            :uid, :client, :phone, :purpose, :notes,
            :photo, :wm_photo, :lat, :lng,
            :addr, :check_in, "pending", NOW()
        )
    ');

    $stmt->execute([
        'uid' => $userId,
        'client' => $clientName,
        'phone' => $clientPhone,
        'purpose' => $meetingPurpose,
        'notes' => $notes,
        'photo' => $savedPhotoRelPath,
        'wm_photo' => $savedPhotoRelPath,
        'lat' => $latitude,
        'lng' => $longitude,
        'addr' => $locationAddress,
        'check_in' => $checkInTime,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // Also record this high-accuracy meeting coordinate in tracking logs
    try {
        $logStmt = $pdo->prepare('
            INSERT INTO sales_tracking_logs (user_id, latitude, longitude, accuracy, speed, recorded_at, created_at)
            VALUES (:uid, :lat, :lng, 5.0, 0.0, :rec_at, NOW())
        ');
        $logStmt->execute([
            'uid' => $userId,
            'lat' => $latitude,
            'lng' => $longitude,
            'rec_at' => $checkInTime,
        ]);
    } catch (_) {}

    // Send notifications to Admin, HR, and Team Lead
    try {
        require_once __DIR__ . '/../../../config/fcm_helper.php';

        $uStmt = $pdo->prepare('SELECT full_name, employee_id FROM users WHERE id = :uid LIMIT 1');
        $uStmt->execute(['uid' => $userId]);
        $empInfo = $uStmt->fetch(PDO::FETCH_ASSOC);
        $empName = $empInfo['full_name'] ?? 'Sales Executive';
        $empId = $empInfo['employee_id'] ?? "ID:$userId";

        $notifTitle = "📍 New Visit Check-In";
        $notifBody = "$empName ($empId) submitted a meeting at '$clientName' ($locationAddress) for approval.";

        $recStmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE (role IN ('super_admin', 'hr', 'sales_head', 'tl') OR id = (SELECT reporting_manager_id FROM users WHERE id = :uid)) 
              AND status = 'active'
        ");
        $recStmt->execute(['uid' => $userId]);
        $recIds = array_unique(array_filter($recStmt->fetchAll(PDO::FETCH_COLUMN)));

        if (!empty($recIds)) {
            $notifInsert = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, is_read, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            foreach ($recIds as $rId) {
                $notifInsert->execute([(int)$rId, $notifTitle, $notifBody]);
            }

            sendFcmToUsers($pdo, $recIds, $notifTitle, $notifBody, [
                'type' => 'visit',
                'meeting_id' => (string)$newId,
                'user_id' => (string)$userId,
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to dispatch meeting notification: ' . $e->getMessage());
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Meeting submitted successfully and is pending Admin approval.',
        'data' => [
            'id' => $newId,
            'user_id' => $userId,
            'client_name' => $clientName,
            'client_phone' => $clientPhone,
            'meeting_purpose' => $meetingPurpose,
            'notes' => $notes,
            'photo_url' => formatMediaUrl($savedPhotoRelPath),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_address' => $locationAddress,
            'check_in_time' => $checkInTime,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ], 201);

} catch (PDOException $e) {
    error_log('create meeting error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to record meeting: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
