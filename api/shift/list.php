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

try {
    $stmt = $pdo->prepare("
        SELECT id, name, start_time, end_time, lunch_start, lunch_end, personal_break_minutes, description, is_active
        FROM shifts
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    $stmt->execute();
    $shiftsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shifts = [];
    foreach ($shiftsRaw as $s) {
        $startFormatted = date('h:i A', strtotime($s['start_time']));
        $endFormatted = date('h:i A', strtotime($s['end_time']));
        $lunchStartFormatted = date('h:i A', strtotime($s['lunch_start']));
        $lunchEndFormatted = date('h:i A', strtotime($s['lunch_end']));

        $shifts[] = [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'start_time' => $startFormatted,
            'end_time' => $endFormatted,
            'time_range' => "$startFormatted - $endFormatted",
            'lunch_start' => $lunchStartFormatted,
            'lunch_end' => $lunchEndFormatted,
            'lunch_time' => "$lunchStartFormatted - $lunchEndFormatted",
            'lunch_minutes' => round((strtotime($s['lunch_end']) - strtotime($s['lunch_start'])) / 60),
            'personal_break_minutes' => (int) $s['personal_break_minutes'],
            'description' => $s['description'],
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Shifts retrieved successfully.',
        'count' => count($shifts),
        'data' => $shifts,
    ], 200);
} catch (PDOException $e) {
    error_log('Shifts list query failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load shifts.',
        'data' => [],
    ], 500);
}
