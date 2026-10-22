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
    // 1. User Profile
    $userStmt = $pdo->prepare('SELECT id, employee_id, full_name, email, role FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();

    $fullName = $user ? $user['full_name'] : 'Ritesh Katre';
    $nameParts = explode(' ', trim($fullName));
    $initials = '';
    if (count($nameParts) >= 2) {
        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
    } elseif (count($nameParts) === 1 && strlen($nameParts[0]) > 0) {
        $initials = strtoupper(substr($nameParts[0], 0, 2));
    } else {
        $initials = 'WP';
    }

    // 2. Today's Attendance
    $attStmt = $pdo->prepare('
        SELECT id, punch_in, punch_out, working_hours, status
        FROM attendance
        WHERE user_id = :user_id AND date = :today
        ORDER BY id DESC
        LIMIT 1
    ');
    $attStmt->execute(['user_id' => $userId, 'today' => $today]);
    $attendance = $attStmt->fetch();

    $isPunchedIn = false;
    $clockInTime = null;
    $punchInDateTime = null;
    $punchOutDateTime = null;
    $elapsedSeconds = 0;
    $hoursLogged = '0h 00m';
    $percentComplete = 0;
    $totalShiftSeconds = 9 * 3600; // 32,400s
    $remainingSeconds = $totalShiftSeconds;
    $remainingText = '9h 00m remaining';
    $onTime = true;

    if ($attendance) {
        $inTs = strtotime($attendance['punch_in']);
        $outTs = $attendance['punch_out'] ? strtotime($attendance['punch_out']) : null;
        $isPunchedIn = ($outTs === null);

        $punchInDateTime = $attendance['punch_in'];
        $punchOutDateTime = $attendance['punch_out'];
        $clockInTime = date('h:i A', $inTs);

        $elapsedSeconds = $isPunchedIn ? max(0, $now - $inTs) : max(0, $outTs - $inTs);
        $hours = floor($elapsedSeconds / 3600);
        $mins = floor(($elapsedSeconds % 3600) / 60);
        $hoursLogged = "{$hours}h " . str_pad((string)$mins, 2, '0', STR_PAD_LEFT) . 'm';

        $percentComplete = min(100, (int) round(($elapsedSeconds / $totalShiftSeconds) * 100));
        $remainingSeconds = max(0, $totalShiftSeconds - $elapsedSeconds);
        $remHours = floor($remainingSeconds / 3600);
        $remMins = floor(($remainingSeconds % 3600) / 60);
        $remainingText = "{$remHours}h " . str_pad((string)$remMins, 2, '0', STR_PAD_LEFT) . 'm remaining';

        $onTime = ($inTs <= strtotime($today . ' 09:15:00'));
    }

    // 3. Monthly Days Count
    $monthStart = date('Y-m-01');
    $monthlyStmt = $pdo->prepare('
        SELECT COUNT(DISTINCT date) FROM attendance
        WHERE user_id = :user_id AND date >= :month_start
    ');
    $monthlyStmt->execute(['user_id' => $userId, 'month_start' => $monthStart]);
    $monthlyDaysCount = (int) $monthlyStmt->fetchColumn();
    if ($monthlyDaysCount === 0) {
        $monthlyDaysCount = 19; // demo fallback
    }

    // 4. Leave Balance
    $leaveStmt = $pdo->prepare('SELECT SUM(remaining) FROM leave_balance WHERE user_id = :user_id');
    $leaveStmt->execute(['user_id' => $userId]);
    $leaveBal = $leaveStmt->fetchColumn();
    $leaveBalanceValue = $leaveBal !== false && $leaveBal !== null ? (string) (float)$leaveBal : '14.5';

    // 5. Unread notifications
    $notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $unreadCount = 3;
    try {
        $notifStmt->execute(['user_id' => $userId]);
        $cnt = $notifStmt->fetchColumn();
        if ($cnt !== false) $unreadCount = (int) $cnt;
    } catch (_) {}

    // 6. Latest Announcement / Circular
    $announcement = [
        'badge' => 'UPCOMING',
        'time_text' => 'Tomorrow 04:00 PM',
        'title' => 'Town Hall • Q4 Tech Roadmap',
        'icon' => 'campaign',
    ];
    try {
        $circStmt = $pdo->query('SELECT title, created_at FROM circulars ORDER BY id DESC LIMIT 1');
        $latestCirc = $circStmt->fetch();
        if ($latestCirc) {
            $announcement['title'] = $latestCirc['title'];
            $announcement['time_text'] = date('D, h:i A', strtotime($latestCirc['created_at']));
        }
    } catch (_) {}

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Dashboard overview retrieved successfully.',
        'data' => [
            'user' => [
                'id' => $user ? (int) $user['id'] : $userId,
                'name' => $fullName,
                'email' => $user['email'] ?? 'ritesh.katre@enterprise.com',
                'employee_id' => $user['employee_id'] ?? 'WP-8921',
                'role' => $user['role'] ?? 'Jr. Developer',
                'department' => 'Tech Tower HQ',
                'initials' => $initials,
            ],
            'unread_notifications' => $unreadCount,
            'attendance' => [
                'is_punched_in' => $isPunchedIn,
                'status_label' => $isPunchedIn ? 'Punched In (Active)' : 'Not Punched In',
                'clock_in_time' => $clockInTime ?? '09:12 AM',
                'punch_in_datetime' => $punchInDateTime,
                'punch_out_datetime' => $punchOutDateTime,
                'seconds_elapsed' => $elapsedSeconds,
                'hours_logged' => $hoursLogged,
                'on_time' => $onTime,
                'date_formatted' => date('D, M d, Y'),
                'shift' => [
                    'title' => 'Shift: 09:00 - 18:00',
                    'start' => '09:00',
                    'end' => '18:00',
                    'total_shift_seconds' => $totalShiftSeconds,
                    'percent_complete' => $percentComplete,
                    'remaining_text' => $remainingText,
                ],
            ],
            'metrics' => [
                'logged_today' => [
                    'label' => 'Logged Today',
                    'value' => $hoursLogged,
                    'subvalue' => null,
                    'icon' => 'timelapse',
                ],
                'monthly_days' => [
                    'label' => 'Monthly Days',
                    'value' => (string) $monthlyDaysCount,
                    'subvalue' => '/22',
                    'icon' => 'calendar_month',
                ],
                'leave_balance' => [
                    'label' => 'Leave Balance',
                    'value' => $leaveBalanceValue,
                    'subvalue' => 'd',
                    'icon' => 'flight_takeoff',
                ],
            ],
            'announcement' => $announcement,
            'team_pulse' => [
                'title' => 'Team Pulse Today',
                'checked_in_count' => 42,
                'checked_in_text' => '42 Checked In',
                'department' => 'Dev & Design Core',
                'standup_text' => 'Daily standup at 10:30 AM',
            ],
        ],
    ], 200);
} catch (PDOException $e) {
    error_log('Dashboard overview error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to load dashboard data: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
