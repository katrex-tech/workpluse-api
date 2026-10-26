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
        $lunchStartFormatted = !empty($s['lunch_start']) ? date('h:i A', strtotime($s['lunch_start'])) : '01:00 PM';
        $lunchEndFormatted = !empty($s['lunch_end']) ? date('h:i A', strtotime($s['lunch_end'])) : '01:30 PM';

        $shifts[] = [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'start_time' => $startFormatted,
            'end_time' => $endFormatted,
            'time_range' => "$startFormatted - $endFormatted",
            'lunch_start' => $lunchStartFormatted,
            'lunch_end' => $lunchEndFormatted,
            'lunch_time' => "$lunchStartFormatted - $lunchEndFormatted",
            'lunch_minutes' => round((strtotime($lunchEndFormatted) - strtotime($lunchStartFormatted)) / 60),
            'personal_break_minutes' => (int) ($s['personal_break_minutes'] ?? 30),
            'description' => $s['description'] ?? '',
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Onboarding shifts retrieved successfully.',
        'count' => count($shifts),
        'data' => $shifts,
    ], 200);
} catch (PDOException $e) {
    error_log('Onboarding shifts query failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load shifts.',
        'data' => [],
    ], 500);
}
