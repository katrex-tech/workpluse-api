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
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

$today = date('Y-m-d');
$now = time();

try {
    $stmt = $pdo->prepare('
        SELECT id, punch_in, punch_out, date, working_hours, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute(['user_id' => $userId, 'today' => $today]);
    $attendance = $stmt->fetch();

    if ($attendance) {
        $inTs = strtotime($attendance['punch_in']);
        $outTs = $attendance['punch_out'] ? strtotime($attendance['punch_out']) : null;
        $isPunchedIn = ($outTs === null);

        $elapsedSeconds = $isPunchedIn ? max(0, $now - $inTs) : max(0, $outTs - $inTs);
        $hours = floor($elapsedSeconds / 3600);
        $mins = floor(($elapsedSeconds % 3600) / 60);
        $hoursLogged = "{$hours}h " . str_pad((string)$mins, 2, '0', STR_PAD_LEFT) . 'm';

        $totalShiftSeconds = 9 * 3600;
        $percentComplete = min(100, (int) round(($elapsedSeconds / $totalShiftSeconds) * 100));
        $remSeconds = max(0, $totalShiftSeconds - $elapsedSeconds);
        $remHours = floor($remSeconds / 3600);
        $remMins = floor(($remSeconds % 3600) / 60);
        $remainingText = "{$remHours}h " . str_pad((string)$remMins, 2, '0', STR_PAD_LEFT) . 'm remaining';

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Today attendance retrieved.',
            'data' => [
                'id' => (int) $attendance['id'],
                'user_id' => $userId,
                'date' => $today,
                'date_formatted' => date('D, M d, Y'),
                'is_punched_in' => $isPunchedIn,
                'punch_in' => $attendance['punch_in'],
                'punch_out' => $attendance['punch_out'],
                'clock_in_time' => date('h:i A', $inTs),
                'clock_out_time' => $outTs ? date('h:i A', $outTs) : null,
                'seconds_elapsed' => $elapsedSeconds,
                'hours_logged' => $hoursLogged,
                'percent_complete' => $percentComplete,
                'remaining_text' => $remainingText,
                'working_hours' => $attendance['working_hours'] !== null ? (float) $attendance['working_hours'] : round($elapsedSeconds / 3600, 2),
                'status' => $attendance['status'],
                'on_time' => ($inTs <= strtotime($today . ' 09:15:00')),
            ],
        ]);
    } else {
        sendJsonResponse([
            'status' => 'success',
            'message' => 'No attendance record for today yet.',
            'data' => [
                'id' => null,
                'user_id' => $userId,
                'date' => $today,
                'date_formatted' => date('D, M d, Y'),
                'is_punched_in' => false,
                'punch_in' => null,
                'punch_out' => null,
                'clock_in_time' => null,
                'clock_out_time' => null,
                'seconds_elapsed' => 0,
                'hours_logged' => '0h 00m',
                'percent_complete' => 0,
                'remaining_text' => '9h 00m remaining',
                'working_hours' => 0.0,
                'status' => 'not_punched_in',
                'on_time' => true,
            ],
        ]);
    }
} catch (PDOException $e) {
    error_log('Today attendance lookup failed: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to load today attendance.',
        'data' => null,
    ], 500);
}
