<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/SalesService.php';

$res = SalesService::calculateTimelineAnalytics($pdo, 51, date('Y-m-d'), 6.0);
echo "Total Distance: " . $res['total_distance_km'] . " KM\n";
echo "Breadcrumb Points: " . count($res['polyline']) . "\n";
echo "Dwell Stops: " . count($res['dwell_stops']) . "\n";
foreach ($res['dwell_stops'] as $s) {
    echo "  - Stop #{$s['stop_number']}: {$s['duration_minutes']} mins at {$s['matched_meeting']} ({$s['start_time']} - {$s['end_time']})\n";
}
echo "Location Alerts: " . count($res['alerts']) . "\n";
foreach ($res['alerts'] as $a) {
    echo "  - Alert: {$a['alert_type']} - {$a['message']} ({$a['time']})\n";
}
echo "TA/DA: ₹" . $res['travel_allowance_amount'] . "\n";
