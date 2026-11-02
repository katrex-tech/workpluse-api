<?php
require_once __DIR__ . '/config/database.php';

$userId = 51; // Vikram Sharma
$today = date('Y-m-d');

// 1. Remove previous demo tracking logs for today to have a clean, realistic set
$pdo->prepare("DELETE FROM sales_tracking_logs WHERE user_id = :uid AND DATE(recorded_at) = :dt")->execute(['uid' => $userId, 'dt' => $today]);
$pdo->prepare("DELETE FROM sales_location_alerts WHERE user_id = :uid AND DATE(created_at) = :dt")->execute(['uid' => $userId, 'dt' => $today]);

// Realistic field sales day route in Nagpur:
// 09:30 AM: Office / HQ (21.1458, 79.0882) - Civil Lines, Nagpur
// 09:45 - 10:15 AM: Transit to MIHAN (~16 KM)
// 10:15 - 11:00 AM: Dwell at TCS MIHAN SEZ (21.0360, 79.0280) [45 mins dwell]
// 11:05 - 11:35 AM: Transit to Butibori (~14 KM)
// 11:35 - 12:15 PM: Dwell at Mahindra Plant, Butibori (20.9250, 78.9950) [40 mins dwell]
// 12:20 - 12:35 PM: GPS was disabled during lunch / transit (Alert logged)
// 12:35 - 01:10 PM: Transit to IT Park Nagpur (~22 KM)
// 01:10 - 01:45 PM: Dwell at Persistent Systems, IT Park (21.1250, 79.0500) [35 mins dwell]

$routeSequence = [
    // 1. Start from Civil Lines
    ['lat' => 21.1458, 'lng' => 79.0882, 'time' => "$today 09:30:00", 'speed' => 0.0],
    ['lat' => 21.1400, 'lng' => 79.0850, 'time' => "$today 09:35:00", 'speed' => 28.5],
    ['lat' => 21.1200, 'lng' => 79.0700, 'time' => "$today 09:45:00", 'speed' => 45.0],
    ['lat' => 21.0800, 'lng' => 79.0500, 'time' => "$today 10:00:00", 'speed' => 52.0],
    ['lat' => 21.0450, 'lng' => 79.0320, 'time' => "$today 10:10:00", 'speed' => 35.0],
    
    // Stop 1: TCS MIHAN SEZ (45 mins stop)
    ['lat' => 21.0360, 'lng' => 79.0280, 'time' => "$today 10:15:00", 'speed' => 0.0],
    ['lat' => 21.0361, 'lng' => 79.0281, 'time' => "$today 10:30:00", 'speed' => 0.0],
    ['lat' => 21.0360, 'lng' => 79.0280, 'time' => "$today 11:00:00", 'speed' => 0.0],
    
    // Transit to Butibori
    ['lat' => 21.0000, 'lng' => 79.0150, 'time' => "$today 11:10:00", 'speed' => 58.0],
    ['lat' => 20.9600, 'lng' => 79.0050, 'time' => "$today 11:22:00", 'speed' => 62.0],
    
    // Stop 2: Mahindra Plant Butibori (40 mins stop)
    ['lat' => 20.9250, 'lng' => 78.9950, 'time' => "$today 11:35:00", 'speed' => 0.0],
    ['lat' => 20.9251, 'lng' => 78.9951, 'time' => "$today 11:55:00", 'speed' => 0.0],
    ['lat' => 20.9250, 'lng' => 78.9950, 'time' => "$today 12:15:00", 'speed' => 0.0],
    
    // Transit towards IT Park
    ['lat' => 20.9800, 'lng' => 79.0100, 'time' => "$today 12:35:00", 'speed' => 55.0],
    ['lat' => 21.0500, 'lng' => 79.0300, 'time' => "$today 12:50:00", 'speed' => 48.0],
    ['lat' => 21.1000, 'lng' => 79.0450, 'time' => "$today 13:05:00", 'speed' => 38.0],
    
    // Stop 3: Persistent Systems IT Park (35 mins stop)
    ['lat' => 21.1250, 'lng' => 79.0500, 'time' => "$today 13:10:00", 'speed' => 0.0],
    ['lat' => 21.1251, 'lng' => 79.0501, 'time' => "$today 13:25:00", 'speed' => 0.0],
    ['lat' => 21.1250, 'lng' => 79.0500, 'time' => "$today 13:45:00", 'speed' => 0.0],
    
    // Return to Civil Lines
    ['lat' => 21.1380, 'lng' => 79.0700, 'time' => "$today 14:00:00", 'speed' => 32.0],
    ['lat' => 21.1458, 'lng' => 79.0882, 'time' => "$today 14:15:00", 'speed' => 0.0],
];

$insLog = $pdo->prepare("
    INSERT INTO sales_tracking_logs (user_id, latitude, longitude, accuracy, speed, battery_level, is_mock, recorded_at, created_at)
    VALUES (?, ?, ?, 5.0, ?, 88, 0, ?, ?)
");

foreach ($routeSequence as $pt) {
    $insLog->execute([$userId, $pt['lat'], $pt['lng'], $pt['speed'], $pt['time'], $pt['time']]);
}

// 2. Insert Location Alert (GPS disabled event)
$insAlert = $pdo->prepare("
    INSERT INTO sales_location_alerts (user_id, alert_type, latitude, longitude, message, is_resolved, created_at)
    VALUES (?, 'gps_disabled', ?, ?, ?, 0, ?)
");
$insAlert->execute([
    $userId,
    20.9500,
    79.0020,
    'Live location chalu rakhna compulsory hai.',
    "$today 12:20:00"
]);

// 3. Ensure meetings match the dwell stops for rich display
$chkMeeting = $pdo->prepare("SELECT COUNT(*) FROM sales_meetings WHERE user_id = :uid AND DATE(check_in_time) = :dt");
$chkMeeting->execute(['uid' => $userId, 'dt' => $today]);
if ($chkMeeting->fetchColumn() < 2) {
    $insMeet = $pdo->prepare("
        INSERT INTO sales_meetings (user_id, client_name, contact_person, contact_phone, meeting_type, notes, photo_url, latitude, longitude, location_address, check_in_time, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insMeet->execute([
        $userId,
        'Tata Consultancy Services',
        'Rajesh Verma',
        '9876543210',
        'Product Demo',
        'Presented WorkPulse field sales tracking. Client requested cloud enterprise quote.',
        'uploads/sales_meetings/demo_watermark_1.jpg',
        21.0360,
        79.0280,
        'TCS MIHAN SEZ Campus, Nagpur',
        "$today 10:15:00",
        'approved'
    ]);
    $insMeet->execute([
        $userId,
        'Mahindra Auto Plant',
        'Kailash Joshi',
        '9823456789',
        'Vendor Onboarding',
        'Discussed plant factory workers attendance with facial biometric recognition.',
        'uploads/sales_meetings/demo_watermark_2.jpg',
        20.9250,
        78.9950,
        'Mahindra MIDC Industrial Zone, Butibori',
        "$today 11:35:00",
        'approved'
    ]);
    $insMeet->execute([
        $userId,
        'Persistent Systems',
        'Sneha Roy',
        '9811223344',
        'Client Visit',
        'HRMS timesheet integration demo. Awaiting procurement review.',
        'uploads/sales_meetings/demo_watermark_3.jpg',
        21.1250,
        79.0500,
        'Persistent IT Park, Gayatri Nagar, Nagpur',
        "$today 13:10:00",
        'pending'
    ]);
}

echo "Successfully seeded demo tracking logs, alert, and meetings for User $userId for $today!\n";
