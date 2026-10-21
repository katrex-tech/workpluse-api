<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST, OPTIONS');
    sendJsonResponse([
        'status' => false,
        'message' => 'Method not allowed.',
        'data' => [],
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';

$isMultipart = isset($_SERVER['CONTENT_TYPE'])
    && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === 0;
$input = [];

if ($isMultipart) {
    $input = $_POST;
} else {
    try {
        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Request body must be valid JSON or multipart form data.',
            'data' => [],
        ], 400);
    }
}

if (!is_array($input)) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Request body must contain an object of fields.',
        'data' => [],
    ], 400);
}

$folderIdInput = $input['folder_id'] ?? null;
$folderId = is_string($folderIdInput) || is_int($folderIdInput)
    ? filter_var($folderIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$uploadedByInput = $input['uploaded_by'] ?? null;
$uploadedBy = is_string($uploadedByInput) || is_int($uploadedByInput)
    ? filter_var($uploadedByInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])
    : false;
$imageUrl = isset($input['image_url']) && is_string($input['image_url'])
    ? trim($input['image_url'])
    : '';
$file = $_FILES['image'] ?? null;
$storedFilePath = null;

if ($folderId === false || $uploadedBy === false) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Valid folder_id and uploaded_by are required.',
        'data' => [],
    ], 422);
}

if ($file !== null) {
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Invalid image upload.',
            'data' => [],
        ], 400);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        sendJsonResponse([
            'status' => false,
            'message' => $file['error'] === UPLOAD_ERR_NO_FILE
                ? 'An image file is required when using file upload.'
                : 'Image upload failed.',
            'data' => [],
        ], 400);
    }

    if (!is_int($file['size']) || $file['size'] <= 0 || $file['size'] > 10 * 1024 * 1024) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Image size must be between 1 byte and 10 MB.',
            'data' => [],
        ], 413);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Invalid uploaded file.',
            'data' => [],
        ], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedImageTypes[$mimeType])) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Only JPEG, PNG, GIF, and WebP images are allowed.',
            'data' => [],
        ], 415);
    }
} elseif ($imageUrl === '' || strlen($imageUrl) > 2048) {
    sendJsonResponse([
        'status' => false,
        'message' => 'Provide a valid image_url or upload an image file using the image field.',
        'data' => [],
    ], 422);
}

try {
    $folderQuery = $pdo->prepare(
        'SELECT id FROM gallery_folders WHERE id = :id LIMIT 1'
    );
    $folderQuery->execute(['id' => $folderId]);
    if ($folderQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Gallery folder not found.',
            'data' => [],
        ], 404);
    }

    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userQuery->execute(['id' => $uploadedBy]);
    if ($userQuery->fetch() === false) {
        sendJsonResponse([
            'status' => false,
            'message' => 'Uploader not found.',
            'data' => [],
        ], 404);
    }

    if ($file !== null) {
        $uploadDirectory = __DIR__ . '/../../uploads/gallery';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Unable to create gallery upload directory.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mimeType];
        $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store uploaded gallery image.');
        }

        $storedFilePath = $destination;
        $imageUrl = 'uploads/gallery/' . $fileName;
    }

    $insert = $pdo->prepare(
        'INSERT INTO gallery_images (folder_id, image_url, uploaded_by)
         VALUES (:folder_id, :image_url, :uploaded_by)'
    );
    $insert->execute([
        'folder_id' => $folderId,
        'image_url' => $imageUrl,
        'uploaded_by' => $uploadedBy,
    ]);
    $imageId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    if ($storedFilePath !== null && is_file($storedFilePath)) {
        unlink($storedFilePath);
    }

    error_log('Gallery image creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to save gallery image.',
        'data' => [],
    ], 500);
} catch (Throwable $exception) {
    if ($storedFilePath !== null && is_file($storedFilePath)) {
        unlink($storedFilePath);
    }

    error_log('Gallery image creation failed: ' . $exception->getMessage());
    sendJsonResponse([
        'status' => false,
        'message' => 'Unable to save gallery image.',
        'data' => [],
    ], 500);
}

sendJsonResponse([
    'status' => true,
    'message' => 'Gallery image uploaded successfully.',
    'data' => [
        'id' => $imageId,
        'folder_id' => $folderId,
        'image_url' => $imageUrl,
        'uploaded_by' => $uploadedBy,
    ],
], 201);
