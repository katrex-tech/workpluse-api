<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/fcm_helper.php';

$res = sendFcmToUsers(
    $pdo,
    [39],
    '🎉 WorkPulse Live Test',
    'Live FCM Push Notification test to your moto g45 5G device is working!',
    [
        'type' => 'test_notification',
        'click_action' => '/notifications',
    ]
);

echo "Push notification send success count: " . $res . "\n";
