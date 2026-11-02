<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/jwt.php';
$config = require __DIR__ . '/config/config.php';

// Generate token for user 51
$token = generateJWT([
    'sub' => '51',
    'employee_id' => 'EMP-SALES-001',
    'role' => 'sales_executive'
], $config['jwt_secret'], 3600);

$ch = curl_init('http://127.0.0.1/workpulse-api/api/sales/home_address.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'latitude' => 21.1458,
    'longitude' => 79.0882,
    'address' => 'Flat 402, Green Valley Apartments, Nagpur',
    'reason' => 'Home address registration for 5 KM field sales geofence'
]));
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "CODE: $code\nRESPONSE: $res\n";
