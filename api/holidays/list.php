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
        'message' => 'Method not allowed. GET required.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

// Optional authenticated check (allows both authenticated users and initial setup)
$currentUser = getAuthenticatedUser(false);

$year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    $whereClauses = [];
    $params = [];

    // Filter by year if specified
    if ($year > 0) {
        $whereClauses[] = 'YEAR(holiday_date) = :year';
        $params['year'] = $year;
    }

    // Filter by category / type
    if ($type !== 'all' && !empty($type)) {
        if (strtolower($type) === 'festival') {
            $whereClauses[] = "type = 'Festival'";
        } elseif (strtolower($type) === 'national') {
            $whereClauses[] = "type = 'National Holiday'";
        } elseif (strtolower($type) === 'gazetted') {
            $whereClauses[] = "(type = 'Gazetted Holiday' OR type = 'National Holiday')";
        } elseif (strtolower($type) === 'optional') {
            $whereClauses[] = 'is_optional = 1';
        } else {
            $whereClauses[] = 'type LIKE :type';
            $params['type'] = '%' . $type . '%';
        }
    }

    // Keyword search
    if (!empty($search)) {
        $whereClauses[] = '(name LIKE :search OR description LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            holiday_date,
            day_of_week,
            type,
            description,
            image_url,
            is_optional,
            DATEDIFF(holiday_date, CURDATE()) AS days_diff
        FROM holidays
        $whereSql
        ORDER BY holiday_date ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $holidays = [];
    $upcomingCount = 0;
    $pastCount = 0;
    $nextHoliday = null;

    $today = date('Y-m-d');

    foreach ($rows as $r) {
        $dateStr = $r['holiday_date'];
        $daysDiff = (int) $r['days_diff'];

        if ($daysDiff > 0) {
            $status = 'upcoming';
            $upcomingCount++;
            if ($nextHoliday === null) {
                $nextHoliday = [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'holiday_date' => $dateStr,
                    'formatted_date' => date('d M Y', strtotime($dateStr)),
                    'day_of_week' => $r['day_of_week'],
                    'type' => $r['type'],
                    'description' => $r['description'],
                    'image_url' => $r['image_url'],
                    'days_remaining' => $daysDiff,
                ];
            }
        } elseif ($daysDiff === 0) {
            $status = 'today';
            $upcomingCount++;
            if ($nextHoliday === null) {
                $nextHoliday = [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'holiday_date' => $dateStr,
                    'formatted_date' => date('d M Y', strtotime($dateStr)),
                    'day_of_week' => $r['day_of_week'],
                    'type' => $r['type'],
                    'description' => $r['description'],
                    'image_url' => $r['image_url'],
                    'days_remaining' => 0,
                ];
            }
        } else {
            $status = 'past';
            $pastCount++;
        }

        $holidays[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'holiday_date' => $dateStr,
            'formatted_date' => date('d M Y', strtotime($dateStr)),
            'day' => date('d', strtotime($dateStr)),
            'month' => date('M', strtotime($dateStr)),
            'year' => (int) date('Y', strtotime($dateStr)),
            'day_of_week' => $r['day_of_week'],
            'type' => $r['type'],
            'description' => $r['description'],
            'image_url' => $r['image_url'],
            'is_optional' => (bool) $r['is_optional'],
            'days_remaining' => $daysDiff,
            'status' => $status,
        ];
    }

    sendJsonResponse([
        'status' => 'success',
        'message' => 'Holidays retrieved successfully.',
        'data' => [
            'summary' => [
                'total_holidays' => count($holidays),
                'upcoming_count' => $upcomingCount,
                'past_count' => $pastCount,
                'current_year' => $year,
                'next_holiday' => $nextHoliday,
            ],
            'holidays' => $holidays,
        ],
    ], 200);

} catch (PDOException $e) {
    error_log('Error retrieving holidays: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Failed to retrieve holidays: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
